<?php
/**
 * Phase 3.45d (Theme A Step 7): pre-flight gate evaluators for the
 * Quick-Start Wizard Launch button.
 *
 * Every gate is a pure function returning
 * `['ok' => bool, 'reason' => string|null]` so the UI can render a row
 * per gate with a green/red badge and an actionable explanation. The
 * launch button stays disabled until `run_all`'s aggregate `ok` is true.
 *
 * Gates that need DB / network / SMTP receive an injectable `?callable`
 * so the unit suite can exercise both branches without leaving the
 * pure-helper tier.
 */

if (!function_exists('taphish_preflight_scope_gate')) {
    /**
     * Every recipient email's domain must be covered by the engagement
     * `scope_allowlist`. An empty allowlist short-circuits to false —
     * we never let the operator launch without an explicit scope.
     *
     * @param string[] $recipientEmails
     * @param string[] $allowlist
     */
    function taphish_preflight_scope_gate(array $recipientEmails, array $allowlist): array
    {
        if (empty($allowlist)) {
            return ['ok' => false, 'reason' => 'Engagement scope_allowlist is empty — define it in Step 1.'];
        }
        if (empty($recipientEmails)) {
            return ['ok' => false, 'reason' => 'No recipients to send to.'];
        }
        $violations = [];
        foreach ($recipientEmails as $email) {
            if (!taphish_engagement_domain_in_scope((string) $email, $allowlist)) {
                $violations[] = (string) $email;
                if (count($violations) >= 5) {
                    break;
                }
            }
        }
        if (count($violations) > 0) {
            return [
                'ok'     => false,
                'reason' => count($violations) . ' recipient(s) outside scope (e.g. ' . implode(', ', array_slice($violations, 0, 3)) . ').',
            ];
        }
        return ['ok' => true, 'reason' => null];
    }
}

if (!function_exists('taphish_preflight_dmarc_gate')) {
    /**
     * DMARC vs sender domain. The hard fail case is: target publishes
     * `p=reject` AND the operator is sending FROM the real target domain
     * (rather than a look-alike). Either pick a look-alike or accept the
     * delivery hit.
     */
    function taphish_preflight_dmarc_gate(string $targetDmarcPolicy, string $senderDomain, string $targetDomain): array
    {
        $policy = strtolower(trim($targetDmarcPolicy));
        $sender = strtolower(trim($senderDomain));
        $target = strtolower(trim($targetDomain));
        if ($policy === 'reject' && $sender !== '' && $target !== '' && $sender === $target) {
            return [
                'ok'     => false,
                'reason' => 'Target publishes DMARC p=reject and you are sending from the real target domain — pick a look-alike.',
            ];
        }
        return ['ok' => true, 'reason' => null];
    }
}

if (!function_exists('taphish_preflight_recipient_count_gate')) {
    function taphish_preflight_recipient_count_gate(int $count): array
    {
        if ($count <= 0) {
            return ['ok' => false, 'reason' => 'Recipient list is empty.'];
        }
        return ['ok' => true, 'reason' => null];
    }
}

if (!function_exists('taphish_preflight_sender_reachable_gate')) {
    /**
     * Has the configured Mail Sender's last SMTP/IMAP probe come back
     * OK? `$probe` is a callback that returns `['ok' => bool, 'error' =>
     * string]`; the wizard wraps the existing `verifyMailboxAccess`.
     */
    function taphish_preflight_sender_reachable_gate(?callable $probe): array
    {
        if ($probe === null) {
            return ['ok' => false, 'reason' => 'No mail-sender probe configured.'];
        }
        $r = $probe();
        if (!empty($r['ok'])) {
            return ['ok' => true, 'reason' => null];
        }
        return ['ok' => false, 'reason' => 'Mail sender probe failed: ' . ($r['error'] ?? 'unknown error')];
    }
}

if (!function_exists('taphish_preflight_webhook_gate')) {
    /**
     * First-capture webhook reachability. Optional — empty URL is "ok
     * with a note" (operator chose to run without an alerting integration).
     */
    function taphish_preflight_webhook_gate(string $webhookUrl, ?callable $probe = null): array
    {
        if ($webhookUrl === '') {
            return ['ok' => true, 'reason' => 'No capture webhook configured (optional).'];
        }
        if ($probe === null) {
            return ['ok' => true, 'reason' => 'Webhook configured; reachability not probed.'];
        }
        $r = $probe($webhookUrl);
        if (!empty($r['ok'])) {
            return ['ok' => true, 'reason' => null];
        }
        return ['ok' => false, 'reason' => 'Webhook unreachable: HTTP ' . (int) ($r['status'] ?? 0)];
    }
}

if (!function_exists('taphish_preflight_run_all')) {
    /**
     * Run every gate from a single context bundle. Returns a structured
     * report the UI can map directly to badge rows + a Launch button
     * gating on `summary.ok`.
     *
     * Context shape (all optional except recipients + allowlist):
     *   recipient_emails: string[]
     *   scope_allowlist:  string[]
     *   target_dmarc_policy: string  (e.g. 'reject', 'quarantine', 'none')
     *   sender_domain:    string
     *   target_domain:    string
     *   sender_probe:     callable|null  (() => ['ok' => bool, 'error' => string])
     *   webhook_url:      string
     *   webhook_probe:    callable|null  ((url) => ['ok' => bool, 'status' => int])
     */
    function taphish_preflight_run_all(array $ctx): array
    {
        $gates = [
            'scope'        => taphish_preflight_scope_gate(
                $ctx['recipient_emails'] ?? [],
                $ctx['scope_allowlist']  ?? []
            ),
            'recipients'   => taphish_preflight_recipient_count_gate(count($ctx['recipient_emails'] ?? [])),
            'dmarc'        => taphish_preflight_dmarc_gate(
                (string) ($ctx['target_dmarc_policy'] ?? ''),
                (string) ($ctx['sender_domain']       ?? ''),
                (string) ($ctx['target_domain']       ?? '')
            ),
            'sender_probe' => taphish_preflight_sender_reachable_gate($ctx['sender_probe'] ?? null),
            'webhook'      => taphish_preflight_webhook_gate(
                (string) ($ctx['webhook_url'] ?? ''),
                $ctx['webhook_probe'] ?? null
            ),
        ];
        $allOk = true;
        foreach ($gates as $g) {
            if (!$g['ok']) {
                $allOk = false;
                break;
            }
        }
        return [
            'ok'    => $allOk,
            'gates' => $gates,
        ];
    }
}
