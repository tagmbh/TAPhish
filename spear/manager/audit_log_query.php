<?php
/**
 * Phase 3.51: audit-log viewer query helpers.
 *
 * Pure helpers + the mysqli facade for filtered audit-log retrieval.
 * The classifier (Phase 3.33) is what turns the freeform `tb_log.log`
 * string into a (kind, severity) pair; this module wraps SQL filtering
 * around that so the operator can search by kind / severity / search
 * substring / date range.
 *
 * The classifier filter is post-SQL (we classify rows after fetching)
 * because tb_log carries the freeform string, not the classifier tags.
 * Trade-off: we over-fetch by N for the kind/severity filter and trim
 * client-side. Acceptable at the scales TAPhish operates at (< 100k
 * rows for the life of any single engagement).
 */

if (!function_exists('audit_log_normalize_filters')) {
    /**
     * Reduce an operator-supplied filter blob into a normalized shape:
     *   {
     *     kind:        ?string  — null = no filter
     *     severity:    ?string  — null = no filter
     *     search:      string   — empty = no filter
     *     username:    string   — empty = no filter
     *     date_from:   ?string  — "YYYY-MM-DD" or null
     *     date_to:     ?string  — "YYYY-MM-DD" or null
     *     limit:       int      — 1..500
     *     offset:      int      — >= 0
     *   }
     *
     * Pure. No I/O.
     */
    function audit_log_normalize_filters(array $in): array
    {
        $allowedKinds = ['AUTH','CAMP','RECP','TMPL','SEND','SCAN','CAPT','ENGM','CLON','BEEF','SYS'];
        $allowedSev   = ['ok','warn','error'];

        $kind = isset($in['kind']) ? (string) $in['kind'] : '';
        $kind = in_array($kind, $allowedKinds, true) ? $kind : null;

        $sev = isset($in['severity']) ? (string) $in['severity'] : '';
        $sev = in_array($sev, $allowedSev, true) ? $sev : null;

        $search   = trim((string) ($in['search']   ?? ''));
        $username = trim((string) ($in['username'] ?? ''));

        $date_from = trim((string) ($in['date_from'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = null;
        $date_to = trim((string) ($in['date_to'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = null;

        $limit  = (int) ($in['limit']  ?? 100);
        $offset = (int) ($in['offset'] ?? 0);
        if ($limit < 1)     $limit  = 1;
        // Phase 3.51 review fix: the export endpoint needs a higher
        // ceiling than the interactive viewer. Cap at 10_000 (vs. the
        // viewer's effective max of 500) so the CSV export doesn't
        // silently truncate when a classifier filter is active.
        if ($limit > 10000) $limit  = 10000;
        if ($offset < 0)    $offset = 0;

        return [
            'kind'      => $kind,
            'severity'  => $sev,
            'search'    => $search,
            'username'  => $username,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'limit'     => $limit,
            'offset'    => $offset,
        ];
    }
}

if (!function_exists('audit_log_apply_classifier_filter')) {
    /**
     * Walk a row set + classify each, drop rows that don't match the
     * (kind, severity) filter. Pure — the classifier itself is pure
     * (Phase 3.33) and we just call it per row.
     *
     * Input rows: [{username, log, ip, date}, ...]
     * Output: [{username, log, ip, date, kind, severity}, ...]
     */
    function audit_log_apply_classifier_filter(array $rows, ?string $kindFilter, ?string $sevFilter): array
    {
        $out = [];
        foreach ($rows as $r) {
            $cls = taphish_classify_log_entry((string) ($r['log'] ?? ''));
            if ($kindFilter !== null && $cls['kind']     !== $kindFilter) continue;
            if ($sevFilter  !== null && $cls['severity'] !== $sevFilter)  continue;
            $out[] = $r + ['kind' => $cls['kind'], 'severity' => $cls['severity']];
        }
        return $out;
    }
}

if (!function_exists('audit_log_rows_to_csv')) {
    /**
     * Render filtered rows as CSV (RFC 4180-ish). The operator
     * downloads this when they want an artifact for a report.
     */
    function audit_log_rows_to_csv(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        if ($h === false) return '';
        // PHP 8.5+ requires the $escape argument explicitly; we pass
        // "\\" to keep RFC-4180-style quoting (no special escape).
        fputcsv($h, ['date', 'username', 'ip', 'kind', 'severity', 'log'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($h, [
                (string) ($r['date']     ?? ''),
                (string) ($r['username'] ?? ''),
                (string) ($r['ip']       ?? ''),
                (string) ($r['kind']     ?? ''),
                (string) ($r['severity'] ?? ''),
                (string) ($r['log']      ?? ''),
            ], ',', '"', '\\');
        }
        rewind($h);
        $csv = stream_get_contents($h) ?: '';
        fclose($h);
        return $csv;
    }
}

if (!function_exists('audit_log_query')) {
    /**
     * DB facade. Pulls a window of rows from tb_log filtered by the
     * SQL-able fields (username / search substring / date range),
     * then applies the classifier filter post-fetch. Returns
     * {rows, total_estimate, has_more}.
     *
     * The kind/severity filter is applied post-fetch so we may need
     * to over-fetch when one of those is active. We multiply the
     * limit by 4 to compensate; if more pagination depth is needed,
     * the operator narrows by date.
     *
     * @return array{rows: array<int,array<string,mixed>>, total_estimate: int, has_more: bool}
     */
    function audit_log_query(\mysqli $conn, array $filters): array
    {
        $f = audit_log_normalize_filters($filters);

        $where = [];
        $bind  = [];
        $types = '';
        if ($f['username'] !== '') {
            $where[] = 'username = ?';
            $bind[]  = $f['username'];
            $types  .= 's';
        }
        if ($f['search'] !== '') {
            $where[] = 'log LIKE ?';
            $bind[]  = '%' . $f['search'] . '%';
            $types  .= 's';
        }
        if ($f['date_from'] !== null) {
            // tb_log.date stores "d-m-Y h:i A" via $entry_time in logIt;
            // for a sane range filter we compare lexicographically on
            // STR_TO_DATE(date, '%d-%m-%Y %h:%i %p').
            $where[] = "STR_TO_DATE(date, '%d-%m-%Y %h:%i %p') >= ?";
            $bind[]  = $f['date_from'] . ' 00:00:00';
            $types  .= 's';
        }
        if ($f['date_to'] !== null) {
            $where[] = "STR_TO_DATE(date, '%d-%m-%Y %h:%i %p') <= ?";
            $bind[]  = $f['date_to'] . ' 23:59:59';
            $types  .= 's';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Over-fetch when classifier filter is active. Ceiling is
        // deliberately higher than the normalize cap (40_000 vs the
        // user-facing 10_000) so a max-limit export with a classifier
        // filter still gets the full matching window. The 40_000 cap
        // is a safety net against a runaway scan.
        $fetchLimit = ($f['kind'] !== null || $f['severity'] !== null)
            ? min(40000, max($f['limit'] * 4, $f['limit']))
            : $f['limit'];

        $sql = "SELECT username, log, ip, date
                  FROM tb_log
                  $whereSql
                 ORDER BY id DESC
                 LIMIT ? OFFSET ?";
        $types .= 'ii';
        $bind[] = $fetchLimit;
        $bind[] = $f['offset'];

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return ['rows' => [], 'total_estimate' => 0, 'has_more' => false];
        }
        $stmt->bind_param($types, ...$bind);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();

        $classified = audit_log_apply_classifier_filter($rows, $f['kind'], $f['severity']);
        $trimmed = array_slice($classified, 0, $f['limit']);
        $hasMore = count($classified) > $f['limit'] || count($rows) >= $fetchLimit;

        return [
            'rows'           => $trimmed,
            'total_estimate' => count($classified),
            'has_more'       => $hasMore,
        ];
    }
}
