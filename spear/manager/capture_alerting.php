<?php
/**
 * Phase 3.42: capture + 2FA-code persistence + first-capture alerting.
 *
 * Three concerns bundled because they share the same data path:
 *
 *  1. Schema migration adding `code_2fa` to tb_data_webform_submit so
 *     a cloned login page that asks for a second factor can store it.
 *  2. Detect the FIRST webform submit per (campaign_id, rid) and POST
 *     a JSON event to a configurable webhook URL (Slack / Teams /
 *     Discord — all accept the same generic shape).
 *  3. Store + retrieve the webhook URL through the existing tb_store
 *     key/value table, encrypted at rest via the Phase 3.27 envelope.
 *
 * The payload builder is pure and tested in isolation. DB / HTTP /
 * crypto wrappers each have a single responsibility so a future
 * integration test can mock at the boundary.
 */

if (!function_exists('taphish_ensure_capture_schema')) {
    /**
     * Idempotently add code_2fa to tb_data_webform_submit on existing
     * installs. Cheap: one information_schema lookup, ALTER only on
     * first run.
     */
    function taphish_ensure_capture_schema(\mysqli $conn): void
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tb_data_webform_submit'
               AND COLUMN_NAME = 'code_2fa'"
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
        @$conn->query("ALTER TABLE tb_data_webform_submit ADD COLUMN code_2fa VARCHAR(16) NULL");
    }
}

if (!function_exists('taphish_capture_webhook_payload')) {
    /**
     * Build the JSON-encodable payload sent to the operator's webhook.
     * Same shape works on Slack ("text" field), Microsoft Teams (the
     * card connector reads "text"), and Discord (renders "content").
     * Embeds the fields under "fields" for richer clients.
     *
     * $event keys (all string except captured_at which is int):
     *   campaign        — campaign name
     *   campaign_id     — opaque id
     *   recipient_name  — operator-supplied name (may be empty)
     *   recipient_email — operator-supplied email
     *   captured_at     — unix epoch ms
     *   page            — int page number on the cloned site (0 = visit)
     *   tld_landing     — landing domain the operator saw clicked
     *   ip              — public IP of the recipient (or empty)
     *   has_2fa         — bool: was a 2FA code among the captured fields
     */
    function taphish_capture_webhook_payload(array $event): array
    {
        $name  = trim((string)($event['recipient_name'] ?? ''));
        $email = trim((string)($event['recipient_email'] ?? ''));
        $camp  = trim((string)($event['campaign'] ?? ''));
        $ip    = trim((string)($event['ip'] ?? ''));
        $page  = (int)($event['page'] ?? 0);
        $ts    = (int)($event['captured_at'] ?? 0);
        $iso   = $ts > 0 ? gmdate('Y-m-d\TH:i:s\Z', intdiv($ts, 1000)) : '';

        $who = $name !== '' ? ($name . ' <' . $email . '>') : $email;
        if ($who === '') $who = 'unknown recipient';

        $headline = sprintf(
            ':bait_and_hook: New capture on *%s* — %s%s%s',
            $camp !== '' ? $camp : '(unnamed campaign)',
            $who,
            $page > 0 ? sprintf(' (page %d)', $page) : '',
            !empty($event['has_2fa']) ? ' [+2FA]' : ''
        );

        return [
            'text'    => $headline,
            'content' => $headline, // Discord
            'fields'  => [
                ['name' => 'Campaign',   'value' => $camp,  'short' => true],
                ['name' => 'Recipient',  'value' => $who,   'short' => true],
                ['name' => 'IP',         'value' => $ip !== '' ? $ip : '—', 'short' => true],
                ['name' => 'Captured',   'value' => $iso !== '' ? $iso : '—', 'short' => true],
                ['name' => '2FA code?',  'value' => !empty($event['has_2fa']) ? 'yes' : 'no', 'short' => true],
                ['name' => 'Page',       'value' => (string)$page, 'short' => true],
            ],
        ];
    }
}

if (!function_exists('taphish_is_first_capture')) {
    /**
     * True iff this (campaign_id, rid) has zero prior rows in
     * tb_data_webform_submit. Called BEFORE the INSERT so the test
     * doesn't see itself.
     */
    function taphish_is_first_capture(\mysqli $conn, string $campaign_id, string $rid): bool
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM tb_data_webform_submit
              WHERE tracker_id = ? AND rid = ?"
        );
        if ($stmt === false) return false;
        $stmt->bind_param('ss', $campaign_id, $rid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row && (int) $row[0] === 0;
    }
}

if (!function_exists('taphish_capture_dispatch_webhook')) {
    /**
     * POST $payload as application/json to $url. Returns true on a
     * 2xx response, false otherwise. 5-second timeout — the tracker
     * endpoint must NOT hang the recipient's browser waiting on a
     * slow Slack.
     */
    function taphish_capture_dispatch_webhook(string $url, array $payload): bool
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }
        $body = json_encode($payload, JSON_INVALID_UTF8_IGNORE);
        if ($body === false) return false;

        $ch = curl_init($url);
        if ($ch === false) return false;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        @curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}

if (!function_exists('taphish_get_capture_webhook_url')) {
    /**
     * Read the configured webhook URL (decrypted) from tb_store. Returns
     * the URL string or '' if not configured / decrypt fails.
     */
    function taphish_get_capture_webhook_url(\mysqli $conn): string
    {
        $stmt = $conn->prepare(
            "SELECT content FROM tb_store WHERE type = 'capture_webhook' AND name = 'url'"
        );
        if ($stmt === false) return '';
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['content'])) return '';
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_passthrough_decrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $plain = secret_at_rest_passthrough_decrypt($row['content'], $key);
                return is_string($plain) ? $plain : '';
            }
        }
        return is_string($row['content']) ? $row['content'] : '';
    }
}

if (!function_exists('taphish_set_capture_webhook_url')) {
    /**
     * Upsert the webhook URL into tb_store. Stored encrypted via the
     * Phase 3.27 envelope when a key is available.
     */
    function taphish_set_capture_webhook_url(\mysqli $conn, string $url): bool
    {
        $payload = $url;
        if ($url !== '' && function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_encrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $enc = secret_at_rest_encrypt($url, $key);
                if ($enc !== null) $payload = $enc;
            }
        }
        $del = $conn->prepare("DELETE FROM tb_store WHERE type='capture_webhook' AND name='url'");
        if ($del !== false) {
            $del->execute();
            $del->close();
        }
        $ins = $conn->prepare(
            "INSERT INTO tb_store (type, name, info, content) VALUES ('capture_webhook', 'url', 'Phase 3.42 first-capture webhook', ?)"
        );
        if ($ins === false) return false;
        $ins->bind_param('s', $payload);
        $ok = $ins->execute();
        $ins->close();
        return (bool) $ok;
    }
}

if (!function_exists('taphish_ensure_capture_schema_v2')) {
    /**
     * Phase 3.45e: idempotently add the two capture-tracking columns
     * needed for the repeat-webhook + 2FA-capture features.
     */
    function taphish_ensure_capture_schema_v2(\mysqli $conn): void
    {
        $columns = [
            ['tb_data_webform_submit', 'is_2fa_capture',      'TINYINT(1) NOT NULL DEFAULT 0'],
            ['tb_data_webform_submit', 'repeat_webhook_sent', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ];
        foreach ($columns as [$table, $col, $type]) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            if ($stmt === false) {
                continue;
            }
            $stmt->bind_param('ss', $table, $col);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($row && (int) $row[0] > 0) {
                continue;
            }
            @$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$type}");
        }
    }
}

if (!function_exists('taphish_should_send_repeat_capture_webhook')) {
    /**
     * Phase 3.45e: repeat-capture webhook guard. A repeat fires only
     * when (a) it isn't the first capture, (b) the row carries a 2FA
     * code (the operationally interesting event), and (c) no repeat
     * webhook has fired for this submit row yet.
     */
    function taphish_should_send_repeat_capture_webhook(array $existingRow): bool
    {
        if (empty($existingRow['code_2fa']) || !empty($existingRow['repeat_webhook_sent'])) {
            return false;
        }
        return true;
    }
}

if (!function_exists('taphish_repeat_capture_webhook_payload')) {
    /**
     * Phase 3.45e: payload for a repeat capture — same shape as the
     * first-capture payload with an explicit `is_repeat: true` flag so
     * the Slack/Teams handler can branch.
     */
    function taphish_repeat_capture_webhook_payload(array $event): array
    {
        $payload = taphish_capture_webhook_payload($event);
        $payload['is_repeat'] = true;
        $payload['text']     = str_replace(':bait_and_hook:', ':repeat:', $payload['text']);
        $payload['content']  = $payload['text'];
        return $payload;
    }
}

if (!function_exists('taphish_capture_summary_for_campaign')) {
    /**
     * Phase 3.45e: per-RID capture summary the dashboard renders next
     * to each recipient row. Aggregates count + concatenated 2FA codes.
     */
    function taphish_capture_summary_for_campaign(\mysqli $conn, string $campaign_id): array
    {
        $stmt = $conn->prepare(
            "SELECT rid, COUNT(*) AS captures, GROUP_CONCAT(code_2fa SEPARATOR ', ') AS codes
             FROM tb_data_webform_submit
             WHERE tracker_id = ?
             GROUP BY rid"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $campaign_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[$r['rid']] = [
                'captures' => (int) $r['captures'],
                'codes'    => $r['codes'],
            ];
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('taphish_tracker_capture_decision')) {
    /**
     * Decide what track.php should do with an incoming hit, given the
     * `SELECT active FROM tb_core_web_tracker_list` row (or null when no such
     * tracker exists).
     *
     *   'drop'           → tracker row exists and active=0 → intentionally
     *                      paused/stopped, ignore the hit (existing behaviour).
     *   'record'         → tracker row exists and is active → record normally.
     *   'record_unknown' → NO tracker row for this id. This is a
     *                      mis-propagated / wrong trackerId (it used to arrive
     *                      as the literal 'Failed' when the landing didn't
     *                      carry it). The old code did `null == 0` → true and
     *                      silently binned EVERY such capture. We record it
     *                      anyway so a real victim submission is never lost,
     *                      and the caller logs it loudly for the operator.
     *
     * @param array<string,mixed>|null $trackerRow  fetch_assoc() result or null
     */
    function taphish_tracker_capture_decision($trackerRow): string
    {
        if (is_array($trackerRow) && array_key_exists('active', $trackerRow)) {
            return ((int) $trackerRow['active'] === 0) ? 'drop' : 'record';
        }
        return 'record_unknown';
    }
}
