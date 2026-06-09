<?php
/**
 * Phase 3.45c (Theme A Step 5): pure-helper module for recipient CSV
 * imports. Extracted from `uploadUserCVS` so the wizard can preview
 * before commit and partial-import (collect bad rows instead of
 * `die()`-ing the whole batch).
 *
 * All parsing + breakdown + scope checking is pure; the actual DB
 * write happens elsewhere (still in `uploadUserCVS` for backward
 * compatibility; the wizard calls a new `wizard_recipient_commit`
 * action that mirrors the same persistence path).
 */

if (!function_exists('taphish_recipient_csv_parse')) {
    /**
     * Parse a CSV blob into a list of `['fname', 'lname', 'email']`
     * rows + a list of errors. Auto-detects the column layout instead
     * of assuming fixed positions, so any of these shapes "just work":
     *
     *   First,Last,Email          Email,First,Last
     *   First,Email,Notes         Email            (single column)
     *   Name,Email                email,first_name,last_name (named header)
     *   First;Last;Email          (semicolon-delimited)
     *   First\tLast\tEmail        (tab-delimited)
     *
     * Strategy:
     *   1. Delimiter autodetect from the first non-blank line (most
     *      frequent of `,` `;` `\t`, default `,`), applied to all rows.
     *   2. Header detection: the first non-blank line is a header when
     *      it mentions mail OR when none of its cells is a valid email.
     *      If named columns are recognised we build a role→index map and
     *      use it for every data row.
     *   3. Otherwise, per data row: the first cell that passes
     *      FILTER_VALIDATE_EMAIL is the email; remaining cells become
     *      fname then lname (a lone "Alice Smith" cell is split on the
     *      first space).
     *
     * Email is always lowercased. Bad/empty rows are collected as
     * errors (partial import) rather than aborting the batch. Strips
     * UTF-8 BOM. Handles CRLF + CR + LF. Skips blank lines.
     *
     * @return array{
     *   rows: array<int, array{fname:string, lname:string, email:string}>,
     *   errors: array<int, array{line:int, email:string, reason:string}>,
     * }
     */
    function taphish_recipient_csv_parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];

        $rows = [];
        $errors = [];

        // --- 1. Find the first non-blank line + autodetect the delimiter.
        $delimiter = ',';
        $firstNonBlankIdx = null;
        foreach ($lines as $idx => $line) {
            if (trim($line) !== '') {
                $firstNonBlankIdx = $idx;
                $delimiter = taphish_recipient_detect_delimiter(trim($line));
                break;
            }
        }

        if ($firstNonBlankIdx === null) {
            return ['rows' => $rows, 'errors' => $errors];
        }

        // --- 2. Header detection on the first non-blank line.
        $headerCells = str_getcsv(trim($lines[$firstNonBlankIdx]), $delimiter, '"', '\\');
        $headerCells = array_map(static fn($c) => trim((string) $c), $headerCells);

        $mentionsMail = false;
        $anyCellIsEmail = false;
        foreach ($headerCells as $cell) {
            if (stripos($cell, 'mail') !== false) {
                $mentionsMail = true;
            }
            if (filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                $anyCellIsEmail = true;
            }
        }
        $isHeader = $mentionsMail || !$anyCellIsEmail;

        // Role→column-index map built from named headers (when present).
        $map = null;
        if ($isHeader) {
            $map = taphish_recipient_map_header($headerCells);
        }

        // --- 3. Walk the data lines.
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($i === $firstNonBlankIdx && $isHeader) {
                // The header line itself is never a data row.
                continue;
            }

            $parts = str_getcsv($line, $delimiter, '"', '\\');
            $parts = array_map(static fn($c) => trim((string) $c), $parts);

            if ($map !== null) {
                [$fname, $lname, $email] = taphish_recipient_extract_by_map($parts, $map);
            } else {
                [$fname, $lname, $email] = taphish_recipient_extract_by_scan($parts);
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = [
                    'line'   => $i + 1,
                    'email'  => $email,
                    'reason' => $email === '' ? 'missing email' : 'invalid email format',
                ];
                continue;
            }

            $rows[] = [
                'fname' => $fname,
                'lname' => $lname,
                'email' => strtolower($email),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }
}

if (!function_exists('taphish_recipient_detect_delimiter')) {
    /**
     * Pick the most frequent of `,` `;` `\t` in a line. Defaults to `,`
     * (ties or zero occurrences keep the comma so single-column files
     * stay sane).
     */
    function taphish_recipient_detect_delimiter(string $line): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0];
        foreach ($candidates as $d => $_) {
            $candidates[$d] = substr_count($line, $d);
        }
        $best = ',';
        $bestCount = 0;
        foreach ($candidates as $d => $count) {
            if ($count > $bestCount) {
                $best = $d;
                $bestCount = $count;
            }
        }
        return $best;
    }
}

if (!function_exists('taphish_recipient_map_header')) {
    /**
     * Map header column names to roles. Returns an associative array
     * with any of the keys 'email', 'fname', 'lname' set to the column
     * index they live in. A bare /name/i column (not first/last) feeds
     * fname when fname isn't otherwise claimed.
     *
     * Returns null when nothing recognisable is found, so the caller
     * falls back to positional/email-scan extraction.
     *
     * @param string[] $headerCells
     * @return array{email?:int, fname?:int, lname?:int}|null
     */
    function taphish_recipient_map_header(array $headerCells): ?array
    {
        $map = [];
        $genericNameIdx = null;
        foreach ($headerCells as $idx => $cell) {
            $c = strtolower(trim($cell));
            if ($c === '') {
                continue;
            }
            if (!isset($map['email']) && preg_match('/mail/i', $c)) {
                $map['email'] = $idx;
                continue;
            }
            if (!isset($map['fname']) && preg_match('/first|vorname|given|fname/i', $c)) {
                $map['fname'] = $idx;
                continue;
            }
            if (!isset($map['lname']) && preg_match('/last|nach|surname|family|lname/i', $c)) {
                $map['lname'] = $idx;
                continue;
            }
            // Generic "name" column — only used for fname if it's free.
            if ($genericNameIdx === null && preg_match('/name/i', $c)) {
                $genericNameIdx = $idx;
            }
        }
        if (!isset($map['fname']) && $genericNameIdx !== null) {
            $map['fname'] = $genericNameIdx;
        }

        return $map === [] ? null : $map;
    }
}

if (!function_exists('taphish_recipient_extract_by_map')) {
    /**
     * Pull fname/lname/email out of a data row using a header role map.
     *
     * @param string[] $parts
     * @param array{email?:int, fname?:int, lname?:int} $map
     * @return array{0:string,1:string,2:string} [fname, lname, email]
     */
    function taphish_recipient_extract_by_map(array $parts, array $map): array
    {
        $email = isset($map['email']) ? (string) ($parts[$map['email']] ?? '') : '';
        $fname = isset($map['fname']) ? (string) ($parts[$map['fname']] ?? '') : '';
        $lname = isset($map['lname']) ? (string) ($parts[$map['lname']] ?? '') : '';

        // Single mapped name column (no separate last-name column) that
        // carries a full "Alice Smith" value → split on the first space,
        // mirroring the header-less scan path.
        if (!isset($map['lname']) && isset($map['fname'])
            && strpos(trim($fname), ' ') !== false) {
            $bits = explode(' ', trim($fname), 2);
            $fname = $bits[0];
            $lname = $bits[1] ?? '';
        }

        // Defensive: if the mapped email cell isn't an email but some
        // other cell is, prefer the real email (handles slightly-off
        // headers gracefully).
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            foreach ($parts as $cell) {
                if (filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                    $email = (string) $cell;
                    break;
                }
            }
        }

        return [$fname, $lname, $email];
    }
}

if (!function_exists('taphish_recipient_extract_by_scan')) {
    /**
     * Pull fname/lname/email out of a data row with no header map: the
     * first email-looking cell is the email; the remaining cells become
     * fname then lname (in order). A lone remaining cell containing a
     * space is split once into fname + lname (e.g. "Alice Smith").
     *
     * @param string[] $parts
     * @return array{0:string,1:string,2:string} [fname, lname, email]
     */
    function taphish_recipient_extract_by_scan(array $parts): array
    {
        $email = '';
        $emailIdx = null;
        foreach ($parts as $idx => $cell) {
            if (filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                $email = (string) $cell;
                $emailIdx = $idx;
                break;
            }
        }

        $rest = [];
        foreach ($parts as $idx => $cell) {
            if ($idx === $emailIdx) {
                continue;
            }
            if (trim((string) $cell) !== '') {
                $rest[] = (string) $cell;
            }
        }

        $fname = '';
        $lname = '';
        if (count($rest) === 1 && strpos(trim($rest[0]), ' ') !== false) {
            // Single "Alice Smith" cell → split on the first space.
            $bits = explode(' ', trim($rest[0]), 2);
            $fname = $bits[0];
            $lname = $bits[1] ?? '';
        } else {
            $fname = $rest[0] ?? '';
            $lname = $rest[1] ?? '';
        }

        return [$fname, $lname, $email];
    }
}

if (!function_exists('taphish_recipient_domain_breakdown')) {
    /**
     * Count recipients per email-domain. The preview card shows this
     * so the operator can sanity-check against the engagement's
     * authorised scope at a glance.
     *
     * @param array<int, array{email:string}> $rows
     * @return array<string, int>   domain → count, sorted by count desc
     */
    function taphish_recipient_domain_breakdown(array $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            $email = strtolower((string) ($r['email'] ?? ''));
            $at = strrpos($email, '@');
            if ($at === false) {
                continue;
            }
            $domain = substr($email, $at + 1);
            if ($domain === '') {
                continue;
            }
            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }
}

if (!function_exists('taphish_recipient_allowlist_violations')) {
    /**
     * Identify rows whose email domain is NOT covered by the engagement
     * `scope_allowlist`. Reuses `taphish_engagement_domain_in_scope`
     * from Phase 3.43a so the subdomain-match semantics stay
     * consistent everywhere.
     *
     * An empty allowlist returns an empty violations list — we don't
     * want to accidentally block every recipient just because the
     * engagement wasn't scoped yet. The wizard renders that as a
     * warning chip, not a hard block.
     *
     * @param array<int, array{email:string}> $rows
     * @param string[] $allowlist
     * @return array<int, array{line_index:int, email:string, domain:string}>
     */
    function taphish_recipient_allowlist_violations(array $rows, array $allowlist): array
    {
        if (empty($allowlist)) {
            return [];
        }
        $violations = [];
        foreach ($rows as $i => $r) {
            $email = (string) ($r['email'] ?? '');
            if (!taphish_engagement_domain_in_scope($email, $allowlist)) {
                $at = strrpos($email, '@');
                $domain = $at === false ? '' : strtolower(substr($email, $at + 1));
                $violations[] = [
                    'line_index' => $i,
                    'email'      => $email,
                    'domain'     => $domain,
                ];
            }
        }
        return $violations;
    }
}

if (!function_exists('taphish_recipient_preview')) {
    /**
     * One-shot summary the wizard's preview card consumes: parse + per-
     * domain count + scope violations. Pure: takes a CSV string + an
     * optional allowlist.
     *
     * @return array{
     *   ok: bool,
     *   row_count: int,
     *   rows: array<int, array{fname:string, lname:string, email:string}>,
     *   domain_breakdown: array<string, int>,
     *   parse_errors: array<int, array<string,mixed>>,
     *   scope_violations: array<int, array<string,mixed>>,
     * }
     */
    function taphish_recipient_preview(string $csv, array $allowlist = []): array
    {
        $parsed = taphish_recipient_csv_parse($csv);
        return [
            'ok'               => count($parsed['errors']) === 0,
            'row_count'        => count($parsed['rows']),
            'rows'             => $parsed['rows'],
            'domain_breakdown' => taphish_recipient_domain_breakdown($parsed['rows']),
            'parse_errors'     => $parsed['errors'],
            'scope_violations' => taphish_recipient_allowlist_violations($parsed['rows'], $allowlist),
        ];
    }
}
