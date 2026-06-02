<?php
/**
 * Phase 3.33: classify a free-text logIt() entry into a 4-letter
 * "kind" tag plus a severity color for the dashboard activity feed.
 *
 * Pure: no DB, no session. The classification table is intentionally
 * explicit (rather than regex-heavy) so adding a new logIt() message
 * shape is a one-line append, not a regex puzzle.
 *
 * Severities map to the brand.css status tokens:
 *   ok    -> --t-success green
 *   warn  -> --t-warn amber
 *   error -> --t-danger red
 */

if (!function_exists('taphish_classify_log_entry')) {
    function taphish_classify_log_entry(string $rawLog): array
    {
        $haystack = strtolower(trim($rawLog));

        // Order matters: more specific phrases first.
        $rules = [
            // [substring, kind, severity]
            // Phase 3.40: scanner hits must classify before the generic
            // 'campaign' rule because the log line carries
            // "for campaign <id>" in its body.
            ['scanner hit',            'SCAN', 'warn'],
            ['2fa disabled',           'AUTH', 'warn'],
            ['2fa enabled',            'AUTH', 'ok'],
            ['failed login',           'AUTH', 'warn'],
            ['account login',          'AUTH', 'ok'],
            ['account logout',         'AUTH', 'ok'],
            ['campaign sent',          'CAMP', 'ok'],
            ['campaign deleted',       'CAMP', 'warn'],
            ['campaign created',       'CAMP', 'ok'],
            ['campaign updated',       'CAMP', 'ok'],
            ['campaign copied',        'CAMP', 'ok'],
            ['campaign',               'CAMP', 'ok'],
            ['recipient list deleted', 'RECP', 'warn'],
            ['recipient list',         'RECP', 'ok'],
            ['recipient',              'RECP', 'ok'],
            ['mail sender error',      'SEND', 'error'],
            ['mail sender',            'SEND', 'ok'],
            ['template deleted',       'TMPL', 'warn'],
            ['template created',       'TMPL', 'ok'],
            ['template',               'TMPL', 'ok'],
        ];

        foreach ($rules as [$needle, $kind, $sev]) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return ['kind' => $kind, 'severity' => $sev];
            }
        }
        return ['kind' => 'SYS', 'severity' => 'ok'];
    }
}
