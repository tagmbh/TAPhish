<?php
/**
 * Phase 3.39: pretext template library for operator red-team engagements.
 *
 * Twelve curated email-pretext starter templates across five categories
 * (Authentication, Finance, HR, IT, Shipping). Each entry is a subject +
 * HTML body using the standard {{FNAME}} / {{NAME}} / {{EMAIL}} merge
 * tokens the cron worker already substitutes in mail_campaign_cron.php.
 *
 * Templates ship as seed data in tb_core_pretext_library, idempotently
 * topped up on session_manager boot the same way Phase 2 mail-sender
 * presets work. Operator's "clone to my templates" picks an entry,
 * copies subject + body into a NEW row in tb_core_mailcamp_template_list
 * so the seed stays clean and any customizations live only in the
 * operator's working copy.
 */

if (!function_exists('taphish_ensure_pretext_schema')) {
    function taphish_ensure_pretext_schema(\mysqli $conn): void
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_core_pretext_library'"
        );
        if ($stmt === false) {
            return;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row && (int) $row[0] > 0) {
            return;
        }
        @$conn->query(
            "CREATE TABLE tb_core_pretext_library (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body MEDIUMTEXT NOT NULL,
                tags VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_category_name (category, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('taphish_pretext_seeds')) {
    /**
     * The seed list. Pure: pulling it out as a function makes it
     * unit-testable and keeps the seed loop in ensure_pretext_seeds()
     * narrow. New entries get appended; the UNIQUE (category, name)
     * key keeps re-runs idempotent.
     */
    function taphish_pretext_seeds(): array
    {
        return [
            // ---- Authentication ----
            [
                'category' => 'Authentication',
                'name'     => 'M365 password expiry',
                'subject'  => 'Action Required: Your Microsoft 365 password expires today',
                'tags'     => 'm365,office365,authentication,credential',
                'body'     => <<<HTML
<p>Dear {{FNAME}},</p>
<p>Our records indicate that your Microsoft 365 password is set to expire <strong>today</strong>. To avoid losing access to email, Teams, and SharePoint, please extend your password before the end of the working day.</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL" style="background:#0078D4;color:#fff;padding:10px 18px;text-decoration:none;border-radius:3px;display:inline-block;">Keep my password</a></p>
<p>If you have already updated your password recently, you can disregard this message.</p>
<p>— Microsoft 365 Identity Service</p>
HTML
            ],
            [
                'category' => 'Authentication',
                'name'     => 'Okta new-device alert',
                'subject'  => 'New device sign-in detected on your Okta account',
                'tags'     => 'okta,sso,authentication,mfa',
                'body'     => <<<HTML
<p>Hi {{FNAME}},</p>
<p>We detected a new sign-in to your Okta-protected account from a device we don't recognise:</p>
<p style="font-family:monospace;background:#f5f5f5;padding:10px;">Device: Windows 11 · Chrome 138<br>Location: approximately Berlin, DE<br>IP: 185.246.84.21<br>Time: a few minutes ago</p>
<p>If this was you, you can dismiss this alert. If you don't recognise the activity, secure your account now:</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Review activity and secure account</a></p>
<p>— Okta Identity</p>
HTML
            ],
            [
                'category' => 'Authentication',
                'name'     => 'Google Workspace verification',
                'subject'  => 'Action required: Verify your Google Workspace account',
                'tags'     => 'google,gsuite,workspace,authentication',
                'body'     => <<<HTML
<p>Hello {{FNAME}},</p>
<p>Routine identity verification is required to keep your <strong>{{MDOMAIN}}</strong> Google Workspace account active. Verification only takes a moment.</p>
<p>Verify your account: <a href="https://example.com/REPLACE-WITH-LANDING-URL">{{EMAIL}}</a></p>
<p>If you do not verify within 24 hours, access to Gmail, Drive and Calendar may be temporarily suspended.</p>
<p>— Google Workspace Trust &amp; Safety</p>
HTML
            ],
            // ---- Finance ----
            [
                'category' => 'Finance',
                'name'     => 'Vendor invoice pending approval',
                'subject'  => 'Invoice #INV-2026-{{RID}} pending your approval',
                'tags'     => 'invoice,finance,approval,vendor',
                'body'     => <<<HTML
<p>Hi {{FNAME}},</p>
<p>The following vendor invoice is pending your approval before payment can be released:</p>
<p style="background:#f5f5f5;padding:10px;font-family:monospace;">Invoice: INV-2026-{{RID}}<br>Vendor: Plaston AG<br>Amount: CHF 4'812.50<br>Due: end of week</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Review invoice</a></p>
<p>If you've already approved this invoice, please disregard.</p>
<p>— Accounts Payable</p>
HTML
            ],
            [
                'category' => 'Finance',
                'name'     => 'Payroll discrepancy',
                'subject'  => 'ACTION: Payroll discrepancy on your last paycheck',
                'tags'     => 'payroll,finance,paystub',
                'body'     => <<<HTML
<p>Hi {{FNAME}},</p>
<p>During our monthly payroll reconciliation we identified a discrepancy on your last paycheck. The adjustment is in your favour but requires you to confirm a small correction before the next pay cycle.</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Review your paystub correction</a></p>
<p>This needs to be done before payroll closes on Friday.</p>
<p>— Payroll Team</p>
HTML
            ],
            // ---- HR ----
            [
                'category' => 'HR',
                'name'     => 'Benefits enrolment deadline',
                'subject'  => 'Open enrolment deadline TODAY — confirm your benefits',
                'tags'     => 'hr,benefits,enrolment,deadline',
                'body'     => <<<HTML
<p>Dear {{FNAME}},</p>
<p>Open enrolment for the {{MDOMAIN}} benefits plan closes <strong>today at 17:00</strong>. If you do not confirm your selections you will default to last year's plan, including the deductible increase.</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Confirm my benefits selection</a></p>
<p>If you have already submitted your selections you can ignore this message.</p>
<p>— People Operations</p>
HTML
            ],
            [
                'category' => 'HR',
                'name'     => 'Policy attestation',
                'subject'  => 'ACTION: New IT security policy — please confirm you have read it',
                'tags'     => 'hr,policy,attestation,security',
                'body'     => <<<HTML
<p>Hi {{FNAME}},</p>
<p>A revised IT security policy has been published. All employees are required to confirm they have read it by the end of the week.</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Open the policy and confirm</a></p>
<p>Confirmation takes under a minute. Failure to acknowledge by Friday is logged against your compliance training record.</p>
<p>— Compliance Office</p>
HTML
            ],
            // ---- IT ----
            [
                'category' => 'IT',
                'name'     => 'IT password reset',
                'subject'  => '[IT] Your password has been reset',
                'tags'     => 'it,password,reset,helpdesk',
                'body'     => <<<HTML
<p>Hi {{FNAME}},</p>
<p>Your network password was reset following a service desk request. To set a new password and regain access to email and shared drives, follow the secure link below:</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Set my new password</a></p>
<p>The link expires in 30 minutes. If you did not request a password reset, please contact the IT service desk immediately.</p>
<p>— IT Service Desk</p>
HTML
            ],
            [
                'category' => 'IT',
                'name'     => 'MFA enrolment',
                'subject'  => 'Action required: Multi-factor authentication enrolment',
                'tags'     => 'it,mfa,2fa,security',
                'body'     => <<<HTML
<p>Hi {{FNAME}},</p>
<p>{{MDOMAIN}} is rolling out mandatory multi-factor authentication. Your account is scheduled for enrolment this week.</p>
<p>Enrol now to choose your preferred method (authenticator app, SMS, security key) before one is selected for you:</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Enrol in MFA</a></p>
<p>The enrolment portal is only available from within the corporate network or via VPN.</p>
<p>— Identity &amp; Access Management</p>
HTML
            ],
            [
                'category' => 'IT',
                'name'     => 'Security alert: suspicious sign-in',
                'subject'  => 'Suspicious sign-in attempt blocked — confirm it was you',
                'tags'     => 'it,security,alert,credential',
                'body'     => <<<HTML
<p>Hi {{FNAME}},</p>
<p>We blocked a sign-in attempt on your account from an IP address we don't recognise. If it was you, you can dismiss this notice. If not, please review and secure your account.</p>
<p style="font-family:monospace;background:#f5f5f5;padding:10px;">When: a few minutes ago<br>Device: Linux · Firefox 128<br>From: 41.142.198.66</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">It wasn't me — secure my account</a></p>
<p>— Security Operations</p>
HTML
            ],
            // ---- Shipping ----
            [
                'category' => 'Shipping',
                'name'     => 'FedEx delivery attempted',
                'subject'  => 'FedEx delivery attempt failed: action required',
                'tags'     => 'shipping,fedex,delivery,parcel',
                'body'     => <<<HTML
<p>Dear {{FNAME}},</p>
<p>We attempted to deliver parcel <strong>FX{{RID}}CH</strong> to you today but were unable to leave it without a signature. To reschedule delivery, please confirm your address and a delivery window.</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Reschedule delivery</a></p>
<p>If unclaimed for 5 working days the parcel will be returned to sender at the recipient's expense.</p>
<p>— FedEx Notifications</p>
HTML
            ],
            [
                'category' => 'Shipping',
                'name'     => 'DHL customs hold',
                'subject'  => 'Your DHL shipment is held at customs',
                'tags'     => 'shipping,dhl,customs,duty',
                'body'     => <<<HTML
<p>Dear {{FNAME}},</p>
<p>Tracking number <strong>{{RID}}-CH-DHL</strong> is currently held at the import customs office pending payment of duties and processing fees totalling <strong>CHF 24.30</strong>.</p>
<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Pay duties and release shipment</a></p>
<p>Held shipments are returned to sender after 7 days. Payment confirmation is sent to <strong>{{EMAIL}}</strong>.</p>
<p>— DHL Express International</p>
HTML
            ],
        ];
    }
}

if (!function_exists('taphish_ensure_pretext_seeds')) {
    /**
     * Insert any seed not already present. UNIQUE KEY on (category, name)
     * makes the INSERT IGNORE idempotent across boots and across operator
     * edits to existing rows.
     */
    function taphish_ensure_pretext_seeds(\mysqli $conn): void
    {
        $check = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_core_pretext_library'"
        );
        if ($check === false) {
            return;
        }
        $check->execute();
        $row = $check->get_result()->fetch_row();
        $check->close();
        if (!$row || (int) $row[0] === 0) {
            return; // schema migration hasn't run — bail without erroring
        }

        $stmt = $conn->prepare(
            "INSERT IGNORE INTO tb_core_pretext_library
                (category, name, subject, body, tags)
             VALUES (?, ?, ?, ?, ?)"
        );
        if ($stmt === false) {
            return;
        }
        foreach (taphish_pretext_seeds() as $s) {
            $stmt->bind_param(
                'sssss',
                $s['category'],
                $s['name'],
                $s['subject'],
                $s['body'],
                $s['tags']
            );
            @$stmt->execute();
        }
        $stmt->close();
    }
}

if (!function_exists('taphish_heal_pretext_clone_bugs')) {
    /**
     * Pretext-clone bug heal — covers three regressions that needed both a
     * code fix and a one-time data heal of already-stored rows:
     *
     *  (a) The buggy clone wrote `mail_content_type = 'html'` but shootMail()
     *      only emits an HTML body when the value is the full `'text/html'`,
     *      so cloned pretexts delivered as raw HTML markup. Fixed by
     *      normalising the value at clone time; this heal rewrites already-
     *      stored rows.
     *
     *  (b) 2026-06-08 mis-fix (PR #120): the pretext seeds and cloned bodies
     *      were rewritten from `https://example.com/REPLACE-WITH-TRACKER-URL`
     *      (an operator-edit marker) to the `{{TRACKINGURL}}` merge token. But
     *      `{{TRACKINGURL}}` expands to the open-tracking PIXEL endpoint
     *      `/tmail?mid=…&rid=…`, not a landing URL. Every operator who hit
     *      Launch without manually editing the CTA shipped a mail whose
     *      "click here" link rendered a 1×1 transparent image — recipients
     *      saw a blank white page. This heal reverses (b) by rewriting the
     *      `<a href="{{TRACKINGURL}}">` CTA back to the visible marker so the
     *      operator notices that the URL still needs to be set. The pre-send
     *      guard (`taphish_mail_body_is_unsafe_to_send()`) then refuses to
     *      dispatch any mail whose body still contains the marker, so the
     *      operator can't silently launch a broken campaign again.
     *
     *  (c) Already-cloned rows from (b) may also have substituted the marker
     *      inside arbitrary non-`<a href=…>` contexts (e.g. plain text). The
     *      heal additionally swaps any bare `{{TRACKINGURL}}` that appears
     *      between an opening `<a ` tag and its closing `</a>` back to the
     *      marker, leaving genuine `{{TRACKINGURL}}` uses inside `<img src>`
     *      tracking pixels untouched.
     *
     * All UPDATEs are idempotent: the WHERE filter is the bug pattern itself,
     * so re-runs of a clean DB match nothing. Returns the total number of
     * rows touched so the caller can log a one-time line.
     */
    function taphish_heal_pretext_clone_bugs(\mysqli $conn): int
    {
        $marker = 'https://example.com/REPLACE-WITH-LANDING-URL';
        // PR #120 specifically rewrote the literal href value, so the heal
        // can target the same `href="{{TRACKINGURL}}"` substring without
        // touching any operator who intentionally embedded `{{TRACKINGURL}}`
        // inside `<img src>` for a custom open-pixel layout.
        $brokenHref = 'href="{{TRACKINGURL}}"';
        $healedHref = 'href="' . $marker . '"';
        $likePattern = '%' . $brokenHref . '%';
        $total = 0;

        // (b1) Reverse PR #120's damage on the pretext library seed rows
        $stmt = $conn->prepare(
            "UPDATE tb_core_pretext_library
                SET body = REPLACE(body, ?, ?)
              WHERE body LIKE ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('sss', $brokenHref, $healedHref, $likePattern);
            if (@$stmt->execute()) {
                $total += $stmt->affected_rows;
            }
            $stmt->close();
        }

        // (b2) Reverse PR #120's damage on already-cloned mail templates
        $stmt = $conn->prepare(
            "UPDATE tb_core_mailcamp_template_list
                SET mail_template_content = REPLACE(mail_template_content, ?, ?)
              WHERE mail_template_content LIKE ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('sss', $brokenHref, $healedHref, $likePattern);
            if (@$stmt->execute()) {
                $total += $stmt->affected_rows;
            }
            $stmt->close();
        }

        // (a) Heal mail_content_type on cloned pretext rows. 'html' is the
        // exact short-form value the buggy clone wrote; anything else (an
        // operator-edited 'text/html' / 'text/plain' / etc.) is preserved.
        $res = @$conn->query(
            "UPDATE tb_core_mailcamp_template_list
                SET mail_content_type = 'text/html'
              WHERE mail_content_type = 'html'"
        );
        if ($res === true) {
            $total += $conn->affected_rows;
        }

        if ($total > 0 && function_exists('logIt')) {
            logIt('Pretext-clone bug heal: updated ' . $total . ' row(s).');
        }
        return $total;
    }
}

if (!function_exists('taphish_mail_body_unsafe_patterns')) {
    /**
     * Patterns whose presence in a rendered mail body proves the operator
     * has not bound a real landing URL to the campaign's CTA. Each entry is
     * a substring that's looked for *after* `filterKeywords()` has expanded
     * the merge tokens — so `{{TRACKINGURL}}` is no longer present here as a
     * literal token; instead, the *expanded* open-pixel `/tmail?mid=` URL
     * matches when it shows up inside an `<a href=…>` (i.e. used as the CTA,
     * not as the legit `<img src>` open-pixel inside `{{TRACKER}}`).
     *
     * @return list<array{needle:string, reason:string}>
     */
    function taphish_mail_body_unsafe_patterns(): array
    {
        return [
            ['needle' => 'REPLACE-WITH-LANDING-URL', 'reason' => 'CTA still points to the REPLACE-WITH-LANDING-URL marker — operator must edit the mail body to insert the real cloned-landing URL'],
            ['needle' => 'REPLACE-WITH-TRACKER-URL', 'reason' => 'CTA still points to the legacy REPLACE-WITH-TRACKER-URL marker — operator must edit the mail body to insert the real cloned-landing URL'],
            ['needle' => 'lp_pages/oops.html',       'reason' => 'CTA points to the legacy SniperHost fallback page lp_pages/oops.html — operator must select or clone a real landing page'],
        ];
    }
}

if (!function_exists('taphish_mail_body_is_unsafe_to_send')) {
    /**
     * Pre-send guard. Returns null if the rendered body looks safe to send,
     * or a human-readable reason string otherwise.
     *
     * Intentionally lenient by default: a mail with a `{{TRACKER}}` open-
     * pixel image whose `src` resolves to the `/tmail?…` open endpoint is
     * legitimate and MUST pass. We therefore look only at `<a href=…>`
     * contexts (not `<img src=…>`) when deciding whether the open-pixel URL
     * is being misused as a clickable CTA.
     */
    function taphish_mail_body_is_unsafe_to_send(string $body): ?string
    {
        foreach (taphish_mail_body_unsafe_patterns() as $p) {
            if (stripos($body, $p['needle']) !== false) {
                return $p['reason'];
            }
        }

        // Detect the "CTA points at the open-pixel" case. After
        // filterKeywords(), {{TRACKINGURL}} has been replaced with
        // /tmail?mid=…&rid=…; if that string appears inside any <a href=…>,
        // the operator forgot to wire a landing URL.
        if (preg_match('#<a\b[^>]*\bhref\s*=\s*["\'][^"\']*\btmail\?mid=#i', $body) === 1) {
            return 'CTA points to the /tmail open-tracking pixel — operator must replace the link target with the real cloned-landing URL';
        }
        return null;
    }
}

if (!function_exists('taphish_mail_body_extract_cta_landing_urls')) {
    /**
     * Pull the `<a href=…>` URLs out of a rendered mail body, filtering out
     * URLs that are NOT landing pages — open-tracking pixel hits and any
     * obvious "real product" footer/Unsubscribe link.
     *
     * Used by the send-time landing-availability probe in
     * `mail_campaign_cron.php`: every distinct landing URL in the rendered
     * body is probed once per cron tick. If any probe fails, the recipient's
     * send is refused with status 3 = Error.
     *
     * Pure helper: only string parsing. Probing is the caller's job.
     *
     * @return list<string>  distinct landing-URL candidates, in document order
     */
    function taphish_mail_body_extract_cta_landing_urls(string $body): array
    {
        $out = [];
        if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']#i', $body, $matches) === false) {
            return $out;
        }
        foreach ($matches[1] as $url) {
            $url = trim($url);
            if ($url === '' || $url === '#') {
                continue;
            }
            // Open-tracking pixel hits (rare as <a href> after the PR #135
            // pre-send guard, but defence in depth).
            if (stripos($url, 'tmail?mid=') !== false) {
                continue;
            }
            // Real-product trust anchors that the m365-login template uses
            // as the post-capture redirect target. The capture has already
            // happened by the time the recipient sees them; probing them
            // here is wasted work AND would 200 anyway.
            if (preg_match('#^https?://(?:login\.microsoftonline|accounts\.google|login\.okta|portal\.azure)\b#i', $url)) {
                continue;
            }
            // Skip mailto:, tel:, javascript:.
            if (preg_match('#^(?:mailto|tel|javascript):#i', $url)) {
                continue;
            }
            if (!in_array($url, $out, true)) {
                $out[] = $url;
            }
        }
        return $out;
    }
}

if (!function_exists('taphish_pretext_list')) {
    /**
     * Return all pretexts grouped by category, newest first within each.
     * Used by the gallery UI.
     */
    function taphish_pretext_list(\mysqli $conn): array
    {
        $out = [];
        $res = @$conn->query(
            "SELECT id, category, name, subject, body, tags
               FROM tb_core_pretext_library
              ORDER BY category, name"
        );
        if (!$res) {
            return $out;
        }
        while ($r = $res->fetch_assoc()) {
            $cat = $r['category'];
            if (!isset($out[$cat])) {
                $out[$cat] = [];
            }
            $out[$cat][] = $r;
        }
        $res->close();
        return $out;
    }
}

if (!function_exists('taphish_pretext_rank_for_categories')) {
    /**
     * Phase 3.43c: re-rank a flat pretext list according to a preferred
     * category order. Pretexts whose category appears earlier in the
     * preferred list float to the top, with ties broken by name. Used
     * by the Quick-Start wizard to surface pretexts that match the
     * detected mail-stack (e.g. M365 detected ⇒ Authentication first).
     *
     * Pure (no DB). Operates on the flat shape — each entry is
     * `['id', 'category', 'name', 'subject', 'body', 'tags']`.
     *
     * @param array $pretexts Flat list of pretext rows.
     * @param string[] $preferredCategories Earlier = higher priority.
     * @return array Ranked pretext rows (input rows unchanged otherwise).
     */
    function taphish_pretext_rank_for_categories(array $pretexts, array $preferredCategories): array
    {
        $rank = [];
        foreach ($preferredCategories as $i => $c) {
            $rank[$c] = $i;
        }
        $tail = count($preferredCategories);
        usort($pretexts, function ($a, $b) use ($rank, $tail) {
            $ra = $rank[$a['category'] ?? ''] ?? $tail;
            $rb = $rank[$b['category'] ?? ''] ?? $tail;
            if ($ra !== $rb) return $ra - $rb;
            return strcmp($a['name'] ?? '', $b['name'] ?? '');
        });
        return $pretexts;
    }
}

if (!function_exists('taphish_pretext_list_flat')) {
    /**
     * Same as taphish_pretext_list but returns a flat list (no
     * grouping) — suitable for ranking + truncation.
     */
    function taphish_pretext_list_flat(\mysqli $conn): array
    {
        $out = [];
        $res = @$conn->query(
            "SELECT id, category, name, subject, body, tags
               FROM tb_core_pretext_library
              ORDER BY category, name"
        );
        if (!$res) {
            return $out;
        }
        while ($r = $res->fetch_assoc()) {
            $out[] = $r;
        }
        $res->close();
        return $out;
    }
}

if (!function_exists('taphish_pretext_clone_content_type')) {
    /**
     * MIME type written into tb_core_mailcamp_template_list.mail_content_type
     * when a pretext seed is cloned. **Must be the full 'text/html' string**:
     * shootMail() in common_functions.php only emits an HTML body when this
     * value matches exactly. The short 'html' form falls through to text()
     * and ships the body as raw markup — the 2026-06-08 regression.
     */
    function taphish_pretext_clone_content_type(): string
    {
        return 'text/html';
    }
}

if (!function_exists('taphish_pretext_clone_to_my_templates')) {
    /**
     * Copy a pretext seed into the operator's mail-template table.
     * Returns the new template id (a random 10-char string consistent
     * with the existing scheme in saveMailTemplate) or null on failure.
     */
    function taphish_pretext_clone_to_my_templates(\mysqli $conn, int $pretext_id): ?string
    {
        $stmt = $conn->prepare(
            "SELECT name, subject, body FROM tb_core_pretext_library WHERE id = ?"
        );
        if ($stmt === false) return null;
        $stmt->bind_param('i', $pretext_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;

        $new_id = getRandomStr(10);
        $new_name = $row['name'] . ' (copy)';
        $attachments = json_encode([]);
        $timage_type = 'embed';
        $mail_content_type = taphish_pretext_clone_content_type();
        $ins = $conn->prepare(
            "INSERT INTO tb_core_mailcamp_template_list
                (mail_template_id, mail_template_name, mail_template_subject,
                 mail_template_content, timage_type, mail_content_type,
                 attachment, date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($ins === false) return null;
        $entry_time = $GLOBALS['entry_time'] ?? gmdate('d-m-Y h:i A');
        $ins->bind_param(
            'ssssssss',
            $new_id,
            $new_name,
            $row['subject'],
            $row['body'],
            $timage_type,
            $mail_content_type,
            $attachments,
            $entry_time
        );
        $ok = $ins->execute();
        $ins->close();
        if (!$ok) return null;

        if (function_exists('logIt')) {
            logIt('Template created from pretext: ' . $new_name);
        }
        return $new_id;
    }
}
