<?php
/**
 * A/B test variant assignment.
 *
 * The cron worker calls ab_assign_variant($rid) before sending each
 * recipient. The assignment is fully deterministic from the RID, so:
 *   - the same recipient on a re-run always lands on the same variant
 *   - no per-recipient state needs to be stored anywhere
 *   - the assignment is reproducible at report-time from the rid alone
 *
 * v1 is exactly two variants. Multivariate (>2) is a follow-up; the
 * cron worker code path is small enough that switching to `% N` here
 * and accepting an N-length template array there is mechanical.
 *
 * Tested in tests/AbVariantsTest.php — no DB, no session.
 */

if (!function_exists('ab_assign_variant')) {
    function ab_assign_variant(string $rid): string
    {
        // crc32 is fast, well-distributed for short inputs, and stable
        // across PHP versions — overkill is fine here, but we want
        // assignments not to drift on a PHP upgrade.
        return (crc32($rid) & 1) === 0 ? 'A' : 'B';
    }
}

if (!function_exists('ab_assignment_summary')) {
    /**
     * For a set of rids, count how many would land in each variant.
     * Useful for the operator preview ("you'll send ~24/50 as A,
     * ~26/50 as B"); not used by the cron loop.
     *
     * @param string[] $rids
     * @return array{A: int, B: int, total: int}
     */
    function ab_assignment_summary(array $rids): array
    {
        $a = 0;
        $b = 0;
        foreach ($rids as $rid) {
            if (!is_string($rid)) {
                continue;
            }
            if (ab_assign_variant($rid) === 'A') {
                $a++;
            } else {
                $b++;
            }
        }
        return ['A' => $a, 'B' => $b, 'total' => $a + $b];
    }
}
