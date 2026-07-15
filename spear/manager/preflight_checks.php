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
     *
     * When no probe is wired the gate degrades to "ok with a note" — the
     * same contract as the webhook + landing gates. A live IMAP/SMTP probe
     * at the exact launch moment is fragile (and the dedicated "Test sender"
     * action already lets the operator verify on demand), so a missing probe
     * must not be the single thing that makes a fully-configured campaign
     * un-launchable. A probe that IS wired and fails still hard-blocks.
     */
    function taphish_preflight_sender_reachable_gate(?callable $probe): array
    {
        if ($probe === null) {
            return ['ok' => true, 'reason' => 'Mail sender selected; reachability not probed (use "Test sender" to verify).'];
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

if (!function_exists('taphish_preflight_http_get')) {
    /**
     * Tiny curl GET helper for the landing-page probe. NOT pure — kept
     * out of any pure-helper assertion so the unit suite can stub the
     * probe rather than hitting the network. Production wraps this as the
     * `landing_probe` callable passed to `run_all`.
     *
     * Returns the shape the landing gate expects:
     *   ['ok' => bool, 'status' => int, 'body' => string, 'error' => string]
     */
    function taphish_preflight_http_get(string $url): array
    {
        if (trim($url) === '' || !function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl unavailable or url empty'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'TAPhish-Preflight/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,*/*'],
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = (string) curl_error($ch);
        curl_close($ch);
        return [
            'ok'     => ($error === '' && $status >= 200 && $status < 400),
            'status' => $status,
            'body'   => $body,
            'error'  => $error,
        ];
    }
}

if (!function_exists('taphish_landing_probe_cached')) {
    /**
     * Send-loop landing probe with a poison-resistant cache.
     *
     * One `InitMailCampaign` process handles the whole recipient batch, so
     * the naive "probe once, cache the verdict for the tick" approach has a
     * nasty failure mode: a single transient timeout/5xx while the FIRST
     * recipient is processed caches a *failure* verdict that then blocks
     * every remaining recipient — one blip aborts the entire campaign.
     *
     * Fix: only SUCCESS verdicts are cached (a known-good host is not
     * re-probed for each of ~100 recipients). A failing probe is retried a
     * few times to absorb transient blips and is NEVER cached, so a genuine
     * outage blocks only the recipients sent during it, and recovery is
     * picked up automatically on the next recipient.
     *
     * @param array $cache   Per-process cache, passed by reference (URL => ok-gate).
     * @param callable $httpGet  URL => ['ok','status','body','error']; stubbable in tests.
     * @return array  ['ok' => bool, 'reason' => string|null]
     */
    function taphish_landing_probe_cached(
        string $landingUrl,
        array &$cache,
        callable $httpGet,
        int $attempts = 3,
        int $retrySleepMs = 200
    ): array {
        if (isset($cache[$landingUrl]) && !empty($cache[$landingUrl]['ok'])) {
            return $cache[$landingUrl];
        }
        $gate = ['ok' => false, 'reason' => 'Landing page not probed.'];
        $attempts = max(1, $attempts);
        for ($n = 0; $n < $attempts; $n++) {
            $probeResult = $httpGet($landingUrl);
            $gate = taphish_preflight_landing_gate($landingUrl, static function () use ($probeResult) {
                return $probeResult;
            });
            if (!empty($gate['ok'])) {
                $cache[$landingUrl] = $gate;   // cache successes only
                return $gate;
            }
            if ($n < $attempts - 1 && $retrySleepMs > 0) {
                usleep($retrySleepMs * 1000);
            }
        }
        return $gate;   // failure: not cached — the next recipient re-probes
    }
}

if (!function_exists('taphish_landing_url_is_probeable')) {
    /**
     * SSRF guard for the landing probe (F2). The server is about to issue an
     * HTTP GET to `$url`; without a guard an authenticated operator could aim
     * it at an internal host (`169.254.169.254`, an admin panel, …). A wizard
     * landing is always a cloned page served from THIS host, so we constrain
     * the probe to exactly that: same host as the request, http(s) scheme, and
     * a path under the cloned-landing prefixes (`/p/<slug>` or the canonical
     * `/spear/sniperhost/cloned/<slug>/`). Everything else is refused.
     *
     * Pure: no DB / network. The host comparison ignores the port so a probe
     * to `host:8099` from a request to the same `host:8099` still matches.
     *
     * `$extraHosts` (Phase 3.60) is an allow-list of operator-configured external
     * landing-host names (e.g. `owa.textilcolor.ch`) — a landing self-hosted on
     * one of those is probeable on ANY path (the whole host is operator-owned),
     * since look-alike landings live at the host root, not under `/p/`.
     *
     * @param string[] $extraHosts
     */
    function taphish_landing_url_is_probeable(string $url, string $requestHost, array $extraHosts = []): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $hostOnly = static function (string $h): string {
            $h = strtolower(trim($h));
            // Strip a :port suffix (but keep bracketed IPv6 intact enough to compare).
            if ($h !== '' && $h[0] !== '[') {
                $colon = strrpos($h, ':');
                if ($colon !== false) {
                    $h = substr($h, 0, $colon);
                }
            }
            return $h;
        };
        $host = $hostOnly($parts['host']);

        // Configured external self-hosted landing host — operator-owned, any path.
        foreach ($extraHosts as $eh) {
            if ($host !== '' && $host === $hostOnly((string) $eh)) {
                return true;
            }
        }

        // Otherwise: must be a cloned page on THIS host.
        if ($requestHost === '' || $host !== $hostOnly($requestHost)) {
            return false;
        }
        $path = $parts['path'] ?? '';
        return strncmp($path, '/p/', 3) === 0
            || strpos($path, '/spear/sniperhost/cloned/') !== false;
    }
}

if (!function_exists('taphish_preflight_landing_gate')) {
    /**
     * Landing-page reachability. Fetches the configured landing URL with an
     * HTTP probe and verifies it returns 200 + an HTML body that contains a
     * `<form>` (so the credential-capture flow has somewhere to submit to).
     *
     * `$probe` is injectable: `(string $url) => ['ok' => bool, 'status' => int,
     * 'body' => string, 'error' => string]`. Production calls it with a curl
     * wrapper; tests pass a stub.
     *
     * An empty URL is a hard fail — the campaign has no landing wired up, so
     * the CTA in the mail body either marker-broken (caught by the mail-body
     * gate below) or pointing at a non-existent path. Either way: refuse.
     */
    function taphish_preflight_landing_gate(string $landingUrl, ?callable $probe): array
    {
        if (trim($landingUrl) === '') {
            return ['ok' => false, 'reason' => 'No landing page selected for the campaign — pick or clone one in Step 6.'];
        }
        if ($probe === null) {
            return ['ok' => true, 'reason' => 'Landing URL configured; reachability not probed.'];
        }
        $r = $probe($landingUrl);
        $status = (int) ($r['status'] ?? 0);
        if (empty($r['ok']) || $status < 200 || $status >= 400) {
            return ['ok' => false, 'reason' => 'Landing page unreachable: HTTP ' . ($status ?: 'error') . ($r['error'] ?? '' ? (' (' . $r['error'] . ')') : '')];
        }
        $body = (string) ($r['body'] ?? '');
        if ($body === '') {
            return ['ok' => false, 'reason' => 'Landing page returned 200 but the body was empty.'];
        }
        // The capture flow needs a <form> on the landing. Without one, the
        // operator's just hosting a static brochure page — no credentials
        // get recorded even on a successful click.
        if (stripos($body, '<form') === false) {
            return ['ok' => false, 'reason' => 'Landing page returned 200 but has no <form> — credentials would have nowhere to submit.'];
        }
        return ['ok' => true, 'reason' => null];
    }
}

if (!function_exists('taphish_preflight_mail_body_gate')) {
    /**
     * Mail-body CTA gate. Runs the same `taphish_mail_body_is_unsafe_to_send`
     * pre-send guard at preflight time, so an operator hitting Launch with an
     * unedited operator-edit marker (or a CTA that expands to the open-pixel)
     * gets a red gate in the wizard instead of silently shipping a campaign
     * whose link lands on a blank white page.
     *
     * The mail body is supplied AFTER `filterKeywords()` has substituted the
     * merge tokens (the wizard's preflight call site does the same render
     * the cron does). Empty body short-circuits to false so a misconfigured
     * template can't accidentally pass.
     */
    function taphish_preflight_mail_body_gate(string $renderedBody): array
    {
        if (trim($renderedBody) === '') {
            return ['ok' => false, 'reason' => 'Mail template body is empty.'];
        }
        if (function_exists('taphish_mail_body_is_unsafe_to_send')) {
            $reason = taphish_mail_body_is_unsafe_to_send($renderedBody);
            if ($reason !== null) {
                return ['ok' => false, 'reason' => 'Mail CTA gate refused: ' . $reason];
            }
        }
        return ['ok' => true, 'reason' => null];
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
     *   landing_url:      string
     *   landing_probe:    callable|null  ((url) => ['ok' => bool, 'status' => int, 'body' => string])
     *   rendered_mail_body: string       (after filterKeywords substitution)
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
            'landing'      => taphish_preflight_landing_gate(
                (string) ($ctx['landing_url'] ?? ''),
                $ctx['landing_probe'] ?? null
            ),
            'mail_body'    => taphish_preflight_mail_body_gate(
                (string) ($ctx['rendered_mail_body'] ?? '')
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
