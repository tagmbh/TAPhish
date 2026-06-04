<?php
/**
 * Phase 3.49 — Recipient PII re-encrypt sweep (pure logic).
 *
 * Forces the at-rest invariant on tb_core_mailcamp_user_group.user_data:
 * every row ends up empty or enc1:-sealed. No DB, no argv, no I/O here — all
 * crypto + persistence is injected so the suite runs offline.
 *
 * Driver: spear/manager/cli/reencrypt_recipient_pii.php
 * Design: docs/superpowers/specs/2026-06-04-phase-3.49-recipient-pii-reencrypt-design.md
 */

if (!function_exists('taphish_reencrypt_plan_user_data')) {
    /**
     * Classify one stored user_data value and decide the action.
     *
     * @param string|null $stored
     * @param callable $sealFn    fn(string): string   (real: recipient_data_seal)
     * @param callable $unsealFn  fn(?string): ?string (real: recipient_data_unseal)
     * @param callable $isEncFn   fn(?string): bool    (real: secret_at_rest_is_encrypted)
     * @return array{action:string, reason:string, sealed:?string, suspect:bool}
     */
    function taphish_reencrypt_plan_user_data($stored, callable $sealFn, callable $unsealFn, callable $isEncFn): array
    {
        if ($stored === null || $stored === '') {
            return ['action' => 'skip-empty', 'reason' => 'empty', 'sealed' => null, 'suspect' => false];
        }
        if ($isEncFn($stored)) {
            return ['action' => 'skip-sealed', 'reason' => 'already sealed', 'sealed' => null, 'suspect' => false];
        }

        // Plaintext: seal it. Goal is no plaintext PII at rest; the round-trip
        // check below guarantees byte-exact preservation, so we never gate on
        // JSON shape — a non-array value is sealed and merely flagged suspect.
        $sealed = $sealFn((string) $stored);
        if (!$isEncFn($sealed)) {
            return ['action' => 'error', 'reason' => 'seal produced no envelope (key unavailable?)', 'sealed' => null, 'suspect' => false];
        }
        if ($unsealFn($sealed) !== $stored) {
            return ['action' => 'error', 'reason' => 'round-trip mismatch', 'sealed' => null, 'suspect' => false];
        }

        $suspect = !is_array(json_decode((string) $stored, true));
        return [
            'action'  => 'seal',
            'reason'  => $suspect ? 'sealed (not a recipient array)' : 'sealed',
            'sealed'  => $sealed,
            'suspect' => $suspect,
        ];
    }
}
