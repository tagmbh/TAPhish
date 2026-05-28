<?php
/**
 * Pure helpers for IMAP bounce detection.
 *
 * The IMAP poll itself lives next to the existing getMailReplied() reply
 * tracker; this module owns the string-level work of deciding whether a
 * message looks like a bounce, extracting the affected RID, and shaping
 * a human-readable reason string. No IMAP calls, no DB. Side-effect-free
 * and tested in isolation in tests/BounceDetectionTest.php.
 *
 * TAPhish injects `{{RID}}@spmailer.generated` as the outgoing Message-ID,
 * so a bounce / DSN that quotes the original Message-ID in its body lets us
 * pin the failure to the exact recipient row in tb_data_mailcamp_live.
 */

if (!function_exists('bounce_is_envelope_likely_bounce')) {
    /**
     * Decide whether the (subject, from) pair smells like a bounce / DSN.
     * Errs on the side of false positives: the caller still has to find
     * a TAPhish RID in the body before doing anything.
     */
    function bounce_is_envelope_likely_bounce(string $subject, string $from): bool
    {
        $needles = [
            'undelivered', 'undeliverable', 'delivery failure', 'delivery status',
            'mail delivery failed', 'returned mail', 'failure notice',
            'message could not be delivered', 'mailer-daemon', 'mail delivery system',
            'permanent failure', 'auto reply',
        ];
        $haystack = strtolower($subject . ' ' . $from);
        foreach ($needles as $n) {
            if (strpos($haystack, $n) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('bounce_extract_rid')) {
    /**
     * Pull the TAPhish RID out of a bounce body by looking for the
     * `{{RID}}@spmailer.generated` Message-ID marker. Returns null if the
     * marker isn't present or the captured RID isn't shaped like one
     * (alpha-num, length 1..32).
     */
    function bounce_extract_rid(string $body): ?string
    {
        // Negative lookbehind so a 33-char run doesn't sneak through by matching its last 32 chars.
        if (!preg_match('/(?<![A-Za-z0-9])([A-Za-z0-9]{1,32})@spmailer\.generated/i', $body, $m)) {
            return null;
        }
        return $m[1];
    }
}

if (!function_exists('bounce_extract_reason')) {
    /**
     * Best-effort one-line bounce reason. Prefers a Diagnostic-Code line
     * if present; falls back to Status / Action / common error fragments,
     * then to a truncated body excerpt.
     */
    function bounce_extract_reason(string $body, int $maxLen = 240): string
    {
        $lines = preg_split('/\r?\n/', $body);
        $candidates = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^Diagnostic-Code:\s*(.+)$/i', $trim, $m)) {
                $candidates[0] = $m[1]; // highest priority
            } elseif (preg_match('/^Status:\s*([0-9.]+)/i', $trim, $m)) {
                $candidates[1] = 'SMTP status ' . $m[1];
            } elseif (preg_match('/^Action:\s*(.+)$/i', $trim, $m)) {
                $candidates[2] = 'Action: ' . $m[1];
            } elseif (preg_match('/(5\d\d\s+[\w\.-]+\s.+)/i', $trim, $m)) {
                $candidates[3] = $m[1];
            }
        }
        ksort($candidates);
        $reason = '';
        foreach ($candidates as $c) {
            $reason = $c;
            break;
        }
        if ($reason === '') {
            $reason = trim($body);
            if ($reason === '') {
                $reason = 'Bounced (no diagnostic available)';
            }
        }
        if (strlen($reason) > $maxLen) {
            // U+2026 HORIZONTAL ELLIPSIS is 3 bytes in UTF-8; budget for it.
            $reason = substr($reason, 0, $maxLen - 3) . '…';
        }
        return $reason;
    }
}

if (!function_exists('bounce_compose_send_error')) {
    /**
     * Format the send_error column value for a bounced row.
     */
    function bounce_compose_send_error(string $reason): string
    {
        return 'Bounced: ' . $reason;
    }
}

if (!function_exists('bounce_poll_due')) {
    /**
     * Phase 3.12: pure throttle helper. Returns true if the last poll was
     * more than $intervalSeconds ago (or never polled). Lives in this pure
     * module so unit tests reach it without mysqli-typed code from
     * bounce_poll.php.
     */
    function bounce_poll_due(?int $lastPolledAt, int $intervalSeconds, int $nowAt): bool
    {
        if ($intervalSeconds <= 0) {
            return false;
        }
        if ($lastPolledAt === null) {
            return true;
        }
        return ($nowAt - $lastPolledAt) >= $intervalSeconds;
    }
}
