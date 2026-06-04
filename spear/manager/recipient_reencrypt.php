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

if (!function_exists('taphish_reencrypt_run')) {
    /**
     * Walk rows, plan each, apply seals (unless dry-run), accumulate a summary.
     *
     * @param iterable $rows         each: ['user_group_id'=>mixed, 'user_data'=>?string]
     * @param callable $applyUpdate  fn(mixed $id, string $sealed): bool
     * @param array    $crypto       ['seal'=>callable,'unseal'=>callable,'isEnc'=>callable]
     * @param bool     $dryRun
     * @return array{scanned:int,skipped_empty:int,skipped_sealed:int,sealed:int,
     *               suspect:int,errors:int,write_failures:int,
     *               suspect_ids:array,error_ids:array,sealed_ids:array}
     */
    function taphish_reencrypt_run(iterable $rows, callable $applyUpdate, array $crypto, bool $dryRun): array
    {
        $cap = 50; // cap id lists for readable output; counts stay exact
        $c   = [
            'scanned'        => 0,
            'skipped_empty'  => 0,
            'skipped_sealed' => 0,
            'sealed'         => 0,
            'suspect'        => 0,
            'errors'         => 0,
            'write_failures' => 0,
            'suspect_ids'    => [],
            'error_ids'      => [],
            'sealed_ids'     => [],
        ];

        $record = static function (array &$bucket, $id) use ($cap): void {
            if (count($bucket) < $cap) {
                $bucket[] = $id;
            }
        };

        foreach ($rows as $row) {
            $c['scanned']++;
            $id   = $row['user_group_id'] ?? null;
            $plan = taphish_reencrypt_plan_user_data(
                $row['user_data'] ?? null,
                $crypto['seal'],
                $crypto['unseal'],
                $crypto['isEnc']
            );

            switch ($plan['action']) {
                case 'skip-empty':
                    $c['skipped_empty']++;
                    break;
                case 'skip-sealed':
                    $c['skipped_sealed']++;
                    break;
                case 'error':
                    $c['errors']++;
                    $record($c['error_ids'], $id);
                    break;
                case 'seal':
                    if (!$dryRun) {
                        if (!$applyUpdate($id, (string) $plan['sealed'])) {
                            $c['write_failures']++;
                            $record($c['error_ids'], $id);
                            break;
                        }
                    }
                    $c['sealed']++;
                    $record($c['sealed_ids'], $id);
                    if ($plan['suspect']) {
                        $c['suspect']++;
                        $record($c['suspect_ids'], $id);
                    }
                    break;
            }
        }

        return $c;
    }
}
