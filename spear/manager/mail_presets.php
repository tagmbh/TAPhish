<?php
/**
 * Pre-configured mail-sender provider presets.
 *
 * Lives in tb_store rows of type='mail_sender'. The TAPhish fork ships extra
 * presets beyond the upstream SniperPhish set; this module keeps the list in
 * one place and inserts missing rows on boot so existing installs gain new
 * presets without a fresh install or manual SQL.
 *
 * Provider rows shape (must match the upstream getStoreList contract):
 *   name    — display label, unique across tb_store
 *   info    — JSON: { dsn_type, disp_note }
 *   content — JSON: { from, username, mailbox{value,disabled,checked}, smtp{value,disabled} }
 *
 * Pure list + ensure-helper. The list is unit-tested in
 * tests/MailPresetsTest.php; the ensure helper is exercised at runtime.
 */

if (!function_exists('taphish_known_mail_presets')) {
    /**
     * Return the canonical list of TAPhish-shipped mail-sender presets, each
     * as ['name' => ..., 'info' => string-json, 'content' => string-json].
     *
     * @return array<int, array{name: string, info: string, content: string}>
     */
    function taphish_known_mail_presets(): array
    {
        return [
            [
                'name' => 'Hostpoint (hostpoint.ch) - SSL',
                'info' => json_encode([
                    'dsn_type'  => 'custom',
                    'disp_note' => 'Swiss host. Implicit-TLS submission on port 465. '
                        . 'Use your full mailbox address as username and the mailbox password. '
                        . 'IMAP is pre-filled for reply tracking.',
                ], JSON_UNESCAPED_SLASHES),
                'content' => json_encode([
                    'from'     => 'Name<username@yourdomain.ch>',
                    'username' => 'username@yourdomain.ch',
                    'mailbox'  => [
                        'value'    => '{imap.hostpoint.ch:993/imap/ssl}INBOX',
                        'disabled' => false,
                        'checked'  => true,
                    ],
                    'smtp' => [
                        'value'    => 'asmtp.mail.hostpoint.ch:465',
                        'disabled' => false,
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ],
            [
                'name' => 'Hostpoint (hostpoint.ch) - TLS',
                'info' => json_encode([
                    'dsn_type'  => 'custom',
                    'disp_note' => 'Swiss host. STARTTLS submission on port 587. '
                        . 'Use your full mailbox address as username and the mailbox password.',
                ], JSON_UNESCAPED_SLASHES),
                'content' => json_encode([
                    'from'     => 'Name<username@yourdomain.ch>',
                    'username' => 'username@yourdomain.ch',
                    'mailbox'  => [
                        'value'    => '{imap.hostpoint.ch:993/imap/ssl}INBOX',
                        'disabled' => false,
                        'checked'  => true,
                    ],
                    'smtp' => [
                        'value'    => 'asmtp.mail.hostpoint.ch:587',
                        'disabled' => false,
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ],
        ];
    }
}

if (!function_exists('taphish_ensure_mail_presets')) {
    /**
     * Idempotently insert any TAPhish-shipped presets that aren't already
     * in tb_store. Safe to call on every request; bails early if everything
     * is present. Uses INSERT IGNORE so existing rows (including operator
     * customizations to the same name) are preserved.
     *
     * Returns the number of rows inserted (0 if nothing needed).
     */
    function taphish_ensure_mail_presets(mysqli $conn): int
    {
        $presets = taphish_known_mail_presets();
        $names = array_column($presets, 'name');
        if ($names === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $types = str_repeat('s', count($names));
        $sql = "SELECT name FROM tb_store WHERE type='mail_sender' AND name IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param($types, ...$names);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }
        $present = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $present[$row['name']] = true;
        }
        $stmt->close();

        $missing = array_filter($presets, fn ($p) => !isset($present[$p['name']]));
        if ($missing === []) {
            return 0;
        }

        $insert = $conn->prepare(
            "INSERT IGNORE INTO tb_store (type, name, info, content) VALUES ('mail_sender', ?, ?, ?)"
        );
        if ($insert === false) {
            return 0;
        }
        $inserted = 0;
        foreach ($missing as $p) {
            $insert->bind_param('sss', $p['name'], $p['info'], $p['content']);
            if ($insert->execute()) {
                $inserted += $insert->affected_rows > 0 ? 1 : 0;
            }
        }
        $insert->close();
        return $inserted;
    }
}
