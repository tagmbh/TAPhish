<?php
/**
 * IMAP bounce-poll worker.
 *
 * Opens the campaign's sender mailbox over IMAP, scans recent messages for
 * delivery-failure envelopes, pins each bounce to the originating TAPhish
 * recipient row via the `{{RID}}@spmailer.generated` Message-ID marker we
 * inject when sending, and flips that row's sending_status to 3 (error)
 * with a `Bounced: <reason>` annotation in send_error.
 *
 * The pure detection logic (envelope / RID / reason) lives in
 * spear/manager/bounce_detection.php and is exercised by unit tests; this
 * file owns only the IMAP + DB plumbing and the per-campaign credential
 * fetch (mirroring the existing getMailReplied() reply-tracker).
 *
 * Idempotent: only rows currently in sending_status=2 are flipped, so a
 * second poll won't downgrade an already-bounced row or clobber an
 * operator-initiated state change.
 */

require_once dirname(__FILE__) . '/bounce_detection.php';

if (!function_exists('bounce_poll_for_campaign')) {
    /**
     * Run one bounce-poll pass against a single campaign's sender mailbox.
     *
     * @return array{
     *   ok: bool,
     *   scanned: int,
     *   matched: int,
     *   updated: int,
     *   errors: string[],
     * }
     */
    function bounce_poll_for_campaign(mysqli $conn, string $campaignId): array
    {
        if (!function_exists('imap_open')) {
            return ['ok' => false, 'scanned' => 0, 'matched' => 0, 'updated' => 0,
                'errors' => ['PHP ext-imap is not installed']];
        }

        $creds = bounce_poll_get_sender_credentials($conn, $campaignId);
        if ($creds === null) {
            return ['ok' => false, 'scanned' => 0, 'matched' => 0, 'updated' => 0,
                'errors' => ['Sender mailbox configuration not found for campaign']];
        }

        $errors = [];
        $scanned = 0;
        $matched = 0;
        $updated = 0;

        // session_write_close so the long-running IMAP call doesn't block other tabs.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $imap = @imap_open($creds['mailbox'], $creds['username'], $creds['password']);
        if ($imap === false) {
            $errors[] = 'imap_open failed: ' . bounce_poll_collect_imap_errors();
            return ['ok' => false, 'scanned' => 0, 'matched' => 0, 'updated' => 0,
                'errors' => $errors];
        }

        try {
            // Scan messages from the last 14 days — a small upper bound that
            // covers typical re-poll cadence without scanning the whole inbox.
            $since = gmdate('j-M-Y', time() - 14 * 86400);
            $candidates = @imap_search($imap, 'SINCE "' . $since . '"') ?: [];

            $rid_to_db_row = bounce_poll_load_campaign_rids($conn, $campaignId);

            foreach ($candidates as $msg_no) {
                $scanned++;
                $overview = @imap_fetch_overview($imap, (string) $msg_no, 0);
                if (!$overview || !isset($overview[0])) {
                    continue;
                }
                $subject = (string) ($overview[0]->subject ?? '');
                $from    = (string) ($overview[0]->from ?? '');
                if (!bounce_is_envelope_likely_bounce($subject, $from)) {
                    continue;
                }
                $body = (string) @imap_body($imap, $msg_no);
                if ($body === '') {
                    continue;
                }
                $rid = bounce_extract_rid($body);
                if ($rid === null || !isset($rid_to_db_row[$rid])) {
                    continue;
                }
                $matched++;
                $reason = bounce_extract_reason($body);
                if (bounce_poll_mark_bounced($conn, $rid, $reason)) {
                    $updated++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        @imap_close($imap);
        $imapErrors = bounce_poll_collect_imap_errors();
        if ($imapErrors !== '') {
            $errors[] = $imapErrors;
        }

        return [
            'ok'      => $errors === [],
            'scanned' => $scanned,
            'matched' => $matched,
            'updated' => $updated,
            'errors'  => $errors,
        ];
    }
}

if (!function_exists('bounce_poll_get_sender_credentials')) {
    /**
     * @return array{mailbox: string, username: string, password: string}|null
     */
    function bounce_poll_get_sender_credentials(mysqli $conn, string $campaignId): ?array
    {
        $stmt = $conn->prepare("SELECT campaign_data FROM tb_core_mailcamp_list WHERE campaign_id = ?");
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $campaignId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row['campaign_data'], true);
        $sender_list_id = $data['mail_sender']['id'] ?? null;
        if (!is_string($sender_list_id) || $sender_list_id === '') {
            return null;
        }

        $stmt = $conn->prepare(
            "SELECT sender_acc_username, sender_acc_pwd, sender_mailbox
             FROM tb_core_mailcamp_sender_list WHERE sender_list_id = ?"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $sender_list_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row || empty($row['sender_mailbox'])) {
            return null;
        }
        return [
            'mailbox'  => (string) $row['sender_mailbox'],
            'username' => (string) ($row['sender_acc_username'] ?? ''),
            'password' => (string) ($row['sender_acc_pwd'] ?? ''),
        ];
    }
}

if (!function_exists('bounce_poll_load_campaign_rids')) {
    /**
     * @return array<string, true>
     */
    function bounce_poll_load_campaign_rids(mysqli $conn, string $campaignId): array
    {
        $stmt = $conn->prepare("SELECT rid FROM tb_data_mailcamp_live WHERE campaign_id = ?");
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $campaignId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($res && $row = $res->fetch_assoc()) {
            $out[(string) $row['rid']] = true;
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('bounce_poll_mark_bounced')) {
    /**
     * Guarded with `sending_status = 2` so a second poll won't overwrite an
     * already-recorded send-time failure (status=3 from the SMTP submission
     * itself) or a row that's still in-flight (status=1).
     */
    function bounce_poll_mark_bounced(mysqli $conn, string $rid, string $reason): bool
    {
        $send_error = bounce_compose_send_error($reason);
        $stmt = $conn->prepare(
            "UPDATE tb_data_mailcamp_live
             SET sending_status = 3, send_error = ?
             WHERE rid = ? AND sending_status = 2"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $send_error, $rid);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        return $changed;
    }
}

if (!function_exists('bounce_poll_collect_imap_errors')) {
    function bounce_poll_collect_imap_errors(): string
    {
        $errors = function_exists('imap_errors') ? (imap_errors() ?: []) : [];
        if (!$errors) {
            return '';
        }
        return implode('; ', array_map('strval', $errors));
    }
}
