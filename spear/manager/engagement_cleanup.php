<?php
/**
 * Pure planner + executor for "wipe these engagements + everything
 * downstream of them". Used by the CLI cleanup script and by tests.
 *
 * Scope (per operator confirmation, 2026-06-09):
 *   DELETE: tb_core_engagement and every row that hangs off it
 *           (campaigns, recipient lists, send log, captures, clone-meta,
 *            engagement-member rows, on-disk cloned site dirs).
 *   KEEP:   tb_main (users), tb_store (secrets), tb_core_pretext_library,
 *           tb_core_mail_template, tb_core_mailcamp_sender_list,
 *           tb_log (audit trail), trackers (no engagement column).
 *
 * Order matters — children first so we never strand orphans. The campaign
 * id of `tb_core_mailcamp_list` is a varchar, so we resolve it BEFORE
 * deleting the campaign row and then strike the send log by that list.
 *
 * Everything that touches the DB goes through one mysqli transaction so a
 * mid-run failure rolls back to the pre-cleanup state.
 */

if (!function_exists('taphish_cleanup_resolve_engagement_ids')) {
    /**
     * Resolve the operator's selector into a concrete list of engagement ids.
     *
     *   ['ids' => [1,2,3]]         exact list
     *   ['status' => 'draft']      every engagement at that status
     *   ['all' => true]            every engagement (nuclear)
     *
     * @return int[] sorted, deduped
     */
    function taphish_cleanup_resolve_engagement_ids(\mysqli $conn, array $selector): array
    {
        if (!empty($selector['ids']) && is_array($selector['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $selector['ids']))));
            sort($ids);
            return $ids;
        }
        if (!empty($selector['status']) && is_string($selector['status'])) {
            $status = $selector['status'];
            $stmt = $conn->prepare("SELECT id FROM tb_core_engagement WHERE status = ?");
            if ($stmt === false) return [];
            $stmt->bind_param('s', $status);
            $stmt->execute();
            $res = $stmt->get_result();
            $ids = [];
            while ($row = $res->fetch_assoc()) { $ids[] = (int) $row['id']; }
            $stmt->close();
            sort($ids);
            return $ids;
        }
        if (!empty($selector['all'])) {
            $res = $conn->query("SELECT id FROM tb_core_engagement");
            if ($res === false) return [];
            $ids = [];
            while ($row = $res->fetch_assoc()) { $ids[] = (int) $row['id']; }
            sort($ids);
            return $ids;
        }
        return [];
    }
}

if (!function_exists('taphish_cleanup_collect_campaign_ids')) {
    /**
     * Find every campaign_id (varchar) owned by the given engagements.
     * Returns [] if the engagement set is empty.
     *
     * @param int[] $engagementIds
     * @return string[]
     */
    function taphish_cleanup_collect_campaign_ids(\mysqli $conn, array $engagementIds): array
    {
        if (!$engagementIds) return [];
        $placeholders = implode(',', array_fill(0, count($engagementIds), '?'));
        $types = str_repeat('i', count($engagementIds));
        $stmt = $conn->prepare("SELECT campaign_id FROM tb_core_mailcamp_list WHERE engagement_id IN ($placeholders)");
        if ($stmt === false) return [];
        $stmt->bind_param($types, ...$engagementIds);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = (string) $row['campaign_id']; }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('taphish_cleanup_collect_clone_slugs')) {
    /**
     * Collect cloned-site slugs that belong to the engagement set so we
     * can rm -rf the on-disk dirs AFTER the DB rows have been deleted.
     *
     * Only runs if the tb_data_clone_meta table exists (it's created lazily
     * by the BeEF integration boot path on first use).
     *
     * @param int[] $engagementIds
     * @return string[] slug list (deduped, never empty strings)
     */
    function taphish_cleanup_collect_clone_slugs(\mysqli $conn, array $engagementIds): array
    {
        if (!$engagementIds) return [];
        // Guard: table may not exist on a fresh install.
        $tbl = $conn->query("SHOW TABLES LIKE 'tb_data_clone_meta'");
        if (!$tbl || $tbl->num_rows === 0) return [];
        $placeholders = implode(',', array_fill(0, count($engagementIds), '?'));
        $types = str_repeat('i', count($engagementIds));
        $stmt = $conn->prepare("SELECT DISTINCT slug FROM tb_data_clone_meta WHERE engagement_id IN ($placeholders)");
        if ($stmt === false) return [];
        $stmt->bind_param($types, ...$engagementIds);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $s = trim((string) ($row['slug'] ?? ''));
            if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('taphish_cleanup_safe_clone_path')) {
    /**
     * Pure: turn a slug into the absolute on-disk path of its cloned-site dir,
     * after rejecting anything that could escape the cloned/ jail.
     *
     * Returns null for slugs that contain path separators, dotfile prefixes,
     * or .. segments — those would let a malicious DB row delete arbitrary
     * directories, and the slug column is a varchar.
     */
    function taphish_cleanup_safe_clone_path(string $clonedRoot, string $slug): ?string
    {
        $s = trim($slug);
        if ($s === '' || $s === '.' || $s === '..') return null;
        if (str_contains($s, '/') || str_contains($s, '\\') || str_contains($s, "\0")) return null;
        if (str_starts_with($s, '.')) return null;
        if (preg_match('/^[A-Za-z0-9._-]+$/', $s) !== 1) return null;
        $root = rtrim($clonedRoot, '/');
        return $root . '/' . $s;
    }
}

if (!function_exists('taphish_cleanup_count_rows_for_ids')) {
    /**
     * Helper for the dry-run report: how many rows of `$table` reference
     * the engagement set via `$column`. Returns -1 if the table doesn't
     * exist (the cleanup will skip it silently — see _execute below).
     */
    function taphish_cleanup_count_rows_for_ids(\mysqli $conn, string $table, string $column, array $ids): int
    {
        if (!$ids) return 0;
        // Table-existence guard (some data tables only get created on first use).
        $tbl = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if (!$tbl || $tbl->num_rows === 0) return -1;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat(is_int($ids[0]) ? 'i' : 's', count($ids));
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM $table WHERE $column IN ($placeholders)");
        if ($stmt === false) return 0;
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0);
    }
}

if (!function_exists('taphish_cleanup_plan')) {
    /**
     * Build a dry-run report: how many rows would be deleted from each table,
     * and which on-disk dirs would be rm -rf'd.
     *
     * @param int[] $engagementIds
     * @return array{
     *   engagement_ids:int[], campaign_ids:string[], clone_slugs:string[],
     *   counts:array<string,int>
     * }
     */
    function taphish_cleanup_plan(\mysqli $conn, array $engagementIds): array
    {
        $campaignIds = taphish_cleanup_collect_campaign_ids($conn, $engagementIds);
        $cloneSlugs  = taphish_cleanup_collect_clone_slugs($conn, $engagementIds);
        $counts = [];
        if ($campaignIds) {
            $counts['tb_data_mailcamp_live'] = taphish_cleanup_count_rows_for_ids(
                $conn, 'tb_data_mailcamp_live', 'campaign_id', $campaignIds
            );
        } else {
            $counts['tb_data_mailcamp_live'] = 0;
        }
        $counts['tb_data_clone_meta']           = taphish_cleanup_count_rows_for_ids(
            $conn, 'tb_data_clone_meta', 'engagement_id', $engagementIds
        );
        $counts['tb_core_engagement_member']    = taphish_cleanup_count_rows_for_ids(
            $conn, 'tb_core_engagement_member', 'engagement_id', $engagementIds
        );
        $counts['tb_core_mailcamp_user_group']  = taphish_cleanup_count_rows_for_ids(
            $conn, 'tb_core_mailcamp_user_group', 'engagement_id', $engagementIds
        );
        $counts['tb_core_mailcamp_list']        = taphish_cleanup_count_rows_for_ids(
            $conn, 'tb_core_mailcamp_list', 'engagement_id', $engagementIds
        );
        $counts['tb_core_engagement']           = taphish_cleanup_count_rows_for_ids(
            $conn, 'tb_core_engagement', 'id', $engagementIds
        );
        return [
            'engagement_ids' => $engagementIds,
            'campaign_ids'   => $campaignIds,
            'clone_slugs'    => $cloneSlugs,
            'counts'         => $counts,
        ];
    }
}

if (!function_exists('taphish_cleanup_execute')) {
    /**
     * Run the deletion inside a single transaction. Returns the same shape
     * as taphish_cleanup_plan plus an `applied=true|false` flag and any
     * `errors` we caught. On any DB error we ROLLBACK and bail.
     *
     * On-disk dir removal happens AFTER the DB COMMIT — if the rm fails
     * we don't roll back the DB (the rows are gone; the dirs are orphans
     * the operator can sweep separately).
     *
     * @param int[] $engagementIds
     */
    function taphish_cleanup_execute(\mysqli $conn, array $engagementIds, string $clonedRoot): array
    {
        $plan = taphish_cleanup_plan($conn, $engagementIds);
        if (!$engagementIds) {
            return $plan + ['applied' => false, 'errors' => ['no engagement ids resolved']];
        }
        $errors = [];
        $conn->begin_transaction();
        try {
            // 1. Send log (keyed by campaign_id varchar)
            $campaignIds = $plan['campaign_ids'];
            if ($campaignIds) {
                $ph = implode(',', array_fill(0, count($campaignIds), '?'));
                $types = str_repeat('s', count($campaignIds));
                $stmt = $conn->prepare("DELETE FROM tb_data_mailcamp_live WHERE campaign_id IN ($ph)");
                if ($stmt !== false) {
                    $stmt->bind_param($types, ...$campaignIds);
                    if (!$stmt->execute()) { $errors[] = 'tb_data_mailcamp_live: ' . $stmt->error; }
                    $stmt->close();
                }
            }
            // 2. Per-engagement-id tables.
            $ph = implode(',', array_fill(0, count($engagementIds), '?'));
            $types = str_repeat('i', count($engagementIds));
            foreach ([
                ['tb_data_clone_meta',          'engagement_id'],
                ['tb_core_engagement_member',   'engagement_id'],
                ['tb_core_mailcamp_user_group', 'engagement_id'],
                ['tb_core_mailcamp_list',       'engagement_id'],
                ['tb_core_engagement',          'id'],
            ] as [$table, $col]) {
                // Table-existence guard
                $tbl = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
                if (!$tbl || $tbl->num_rows === 0) continue;
                $stmt = $conn->prepare("DELETE FROM $table WHERE $col IN ($ph)");
                if ($stmt === false) { $errors[] = "$table: prepare failed"; continue; }
                $stmt->bind_param($types, ...$engagementIds);
                if (!$stmt->execute()) { $errors[] = "$table: " . $stmt->error; }
                $stmt->close();
            }
            if ($errors) {
                $conn->rollback();
                return $plan + ['applied' => false, 'errors' => $errors];
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            return $plan + ['applied' => false, 'errors' => [$e->getMessage()]];
        }
        // 3. On-disk cloned-site dirs (post-commit; orphan-tolerant)
        $removedDirs = [];
        $skippedDirs = [];
        foreach ($plan['clone_slugs'] as $slug) {
            $dir = taphish_cleanup_safe_clone_path($clonedRoot, $slug);
            if ($dir === null) { $skippedDirs[] = $slug . ' (unsafe slug)'; continue; }
            if (!is_dir($dir))  { continue; }
            if (taphish_cleanup_rrmdir($dir)) { $removedDirs[] = $dir; }
            else { $skippedDirs[] = $dir . ' (rm failed)'; }
        }
        return $plan + [
            'applied'      => true,
            'errors'       => [],
            'removed_dirs' => $removedDirs,
            'skipped_dirs' => $skippedDirs,
        ];
    }
}

if (!function_exists('taphish_cleanup_rrmdir')) {
    /**
     * Recursive rmdir. Returns true on full removal, false on any failure.
     * Refuses to follow symlinks (rejects the dir itself; unlinks symlinked
     * children rather than recursing into them).
     */
    function taphish_cleanup_rrmdir(string $dir): bool
    {
        if (is_link($dir)) return false;
        if (!is_dir($dir)) return false;
        $items = @scandir($dir);
        if ($items === false) return false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                if (!@unlink($path)) return false;
            } elseif (is_dir($path)) {
                if (!taphish_cleanup_rrmdir($path)) return false;
            } else {
                if (!@unlink($path)) return false;
            }
        }
        return @rmdir($dir);
    }
}
