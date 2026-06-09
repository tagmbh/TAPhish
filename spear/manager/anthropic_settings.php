<?php
/**
 * Anthropic API key — encrypted at-rest in tb_store.
 *
 * Stores a single secret (the operator's `sk-ant-…` key) so the
 * AI landing-page generator (and any future AI-assisted feature)
 * can pick it up without the operator pasting it on every request.
 *
 * Same posture as the Phase 3.53 Telegram bot config:
 *   - Encrypted at-rest via the Phase 3.38 envelope (secret_at_rest_*).
 *   - REFUSES to write plaintext if the envelope is unavailable —
 *     better to fail loudly than silently land an `sk-ant-…` in the
 *     clear in `tb_store.content`.
 *   - The key is NEVER returned to the browser after it's saved; the
 *     load action returns a fixed-width mask and a configured-bool only.
 *   - Saving an empty key DELETES the row (disables AI features).
 */

if (!function_exists('taphish_anthropic_validate_api_key')) {
    /**
     * Anthropic console keys start `sk-ant-…` and run 90+ url-safe chars.
     * Reject anything else before issuing a request so a fat-fingered
     * paste doesn't end up in the `x-api-key:` header.
     */
    function taphish_anthropic_validate_api_key(string $key): bool
    {
        $k = trim($key);
        return preg_match('/^sk-ant-[A-Za-z0-9_-]{40,}$/', $k) === 1;
    }
}

if (!function_exists('taphish_anthropic_mask_api_key')) {
    /**
     * `sk-ant-api03-7YGX…` → `sk-ant-api03-7YGX••••••••`.
     * Show enough characters that the operator can recognise WHICH key
     * is set without revealing enough to use it.
     */
    function taphish_anthropic_mask_api_key(string $key): string
    {
        $k = trim($key);
        if ($k === '') return '';
        return mb_substr($k, 0, 16) . '••••••••';
    }
}

if (!function_exists('taphish_anthropic_set_api_key')) {
    /**
     * Upsert the encrypted Anthropic API key into tb_store
     * (type='anthropic', name='api_key'). Empty key deletes the row.
     *
     * Returns true on persisted change, false on refused (e.g. envelope
     * unavailable, malformed key, db error). Callers should re-check
     * `taphish_anthropic_get_api_key()` after a save if they need to
     * confirm what actually made it to disk.
     */
    function taphish_anthropic_set_api_key(\mysqli $conn, string $apiKey): bool
    {
        $k = trim($apiKey);
        if ($k === '') {
            $del = $conn->prepare("DELETE FROM tb_store WHERE type='anthropic' AND name='api_key'");
            if ($del === false) return false;
            $ok = $del->execute();
            $del->close();
            return (bool) $ok;
        }
        if (!taphish_anthropic_validate_api_key($k)) {
            return false;
        }

        $payload   = $k;
        $encrypted = false;
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_encrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $enc = secret_at_rest_encrypt($payload, $key);
                if ($enc !== null) { $payload = $enc; $encrypted = true; }
            }
        }
        if (!$encrypted) {
            // Don't ever land a live `sk-ant-…` in plaintext.
            return false;
        }

        $del = $conn->prepare("DELETE FROM tb_store WHERE type='anthropic' AND name='api_key'");
        if ($del !== false) { $del->execute(); $del->close(); }
        $ins = $conn->prepare(
            "INSERT INTO tb_store (type, name, info, content) VALUES ('anthropic', 'api_key', 'Anthropic API key (encrypted at-rest, AI landing generator + future AI features)', ?)"
        );
        if ($ins === false) return false;
        $ins->bind_param('s', $payload);
        $ok = $ins->execute();
        $ins->close();
        return (bool) $ok;
    }
}

if (!function_exists('taphish_anthropic_get_api_key')) {
    /**
     * Read + decrypt the Anthropic API key. Returns null if unset
     * or undecryptable. Callers MUST treat the return value as a
     * secret — never log it, never echo it.
     */
    function taphish_anthropic_get_api_key(\mysqli $conn): ?string
    {
        $stmt = $conn->prepare("SELECT content FROM tb_store WHERE type='anthropic' AND name='api_key'");
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
        if (!taphish_anthropic_validate_api_key($payload)) {
            return null;
        }
        return $payload;
    }
}
