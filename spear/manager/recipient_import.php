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
     * rows + a list of errors. Accepts both the "fname,lname,email"
     * and "fname,email,notes" shapes the existing UI lets operators
     * upload — same heuristic as the legacy `uploadUserCVS` path. The
     * first non-blank line is treated as the header row and dropped
     * (lowercased "email" or "e-mail" anywhere in it = header).
     *
     * Strips UTF-8 BOM. Handles CRLF + CR + LF. Skips blank lines.
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
        $headerSkipped = false;

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!$headerSkipped) {
                $headerSkipped = true;
                if (stripos($line, 'email') !== false || stripos($line, 'e-mail') !== false) {
                    continue;
                }
                // No header detected — re-process this line below.
            }
            $parts = str_getcsv($line, ',', '"', '\\');
            $fname = trim((string) ($parts[0] ?? ''));
            $lname = trim((string) ($parts[1] ?? ''));
            $email = trim((string) ($parts[2] ?? ''));

            // Same fallback as legacy: if cell-2 isn't an email, try cell-1.
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)
                && filter_var($lname, FILTER_VALIDATE_EMAIL)) {
                $email = $lname;
                $lname = '';
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
