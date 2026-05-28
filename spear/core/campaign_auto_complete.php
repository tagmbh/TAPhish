<?php
/**
 * Campaign auto-complete pass.
 *
 * Invoked from the main cron loop in SniperPhish_Manager.php once per
 * iteration. Scans tracking-phase campaigns (camp_status = 4: mails done
 * sending, engagement still being collected) and transitions them to the
 * terminal state (camp_status = 3) once the share of recipients who
 * engaged with the email crosses the operator-configured threshold from
 * mconfig_data.auto_complete_threshold_percent (default 100, 0 disables).
 *
 * Engagement is "opened the email at least once" (mail_open_times
 * non-empty). Phase 3 follow-ups can extend this to count web-tracker
 * clicks / submits too.
 *
 * The pure threshold logic lives in spear/manager/campaign_completion.php
 * and is unit-tested in isolation; this file owns only the DB plumbing.
 */

require_once dirname(__FILE__, 2) . '/manager/campaign_completion.php';

if (!function_exists('autoCompleteEngagedCampaigns')) {
    /**
     * Run one auto-complete pass over all tracking-phase campaigns.
     * Returns the number of campaigns transitioned to status=3 this pass.
     */
    function autoCompleteEngagedCampaigns(mysqli $conn): int
    {
        $rows = autoCompleteListTrackingCampaigns($conn);
        $completed = 0;
        foreach ($rows as $row) {
            $config = autoCompleteResolveConfig($conn, $row['campaign_data']);
            if ($config['threshold'] === 0) {
                continue;
            }
            [$engaged, $total] = autoCompleteCountEngagement(
                $conn, $row['campaign_id'], $config['metric']
            );
            if (auto_complete_should_trigger($engaged, $total, $config['threshold'])) {
                if (autoCompleteTransition($conn, $row['campaign_id'])) {
                    $completed++;
                    if (function_exists('logIt')) {
                        logIt(
                            'Mail campaign auto-completed: ' . $engaged . '/' . $total
                            . ' recipients engaged (' . $config['metric']
                            . ' >= ' . $config['threshold'] . '%)',
                            'cron'
                        );
                    }
                }
            }
        }
        return $completed;
    }
}

if (!function_exists('autoCompleteListTrackingCampaigns')) {
    /**
     * @return array<int, array{campaign_id: string, campaign_data: array<mixed>}>
     */
    function autoCompleteListTrackingCampaigns(mysqli $conn): array
    {
        $out = [];
        $res = $conn->query("SELECT campaign_id, campaign_data FROM tb_core_mailcamp_list WHERE camp_status = 4");
        if ($res === false) {
            return $out;
        }
        while ($row = $res->fetch_assoc()) {
            $data = json_decode((string) $row['campaign_data'], true);
            $out[] = [
                'campaign_id'   => (string) $row['campaign_id'],
                'campaign_data' => is_array($data) ? $data : [],
            ];
        }
        $res->free();
        return $out;
    }
}

if (!function_exists('autoCompleteResolveThreshold')) {
    /**
     * Pull the auto-complete threshold from mconfig_data referenced by the
     * campaign. Falls back to default 100 if the mconfig is missing or the
     * field isn't set; returns 0 to disable when the operator explicitly
     * set 0.
     *
     * @param array<mixed> $campaignData
     */
    function autoCompleteResolveThreshold(mysqli $conn, array $campaignData): int
    {
        return autoCompleteResolveConfig($conn, $campaignData)['threshold'];
    }
}

if (!function_exists('autoCompleteResolveConfig')) {
    /**
     * Phase 3.15: pull both the threshold and the engagement metric from
     * the campaign's mconfig in one query. Defaults: threshold=100,
     * metric='opens' (Phase 3.3 behavior).
     *
     * @param array<mixed> $campaignData
     * @return array{threshold: int, metric: string}
     */
    function autoCompleteResolveConfig(mysqli $conn, array $campaignData): array
    {
        $default = ['threshold' => 100, 'metric' => 'opens'];
        $mconfigId = $campaignData['mconfig_id'] ?? null;
        if (!is_string($mconfigId) || $mconfigId === '') {
            return $default;
        }
        $stmt = $conn->prepare("SELECT mconfig_data FROM tb_core_mailcamp_config WHERE mconfig_id = ?");
        if ($stmt === false) {
            return $default;
        }
        $stmt->bind_param('s', $mconfigId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return $default;
        }
        $decoded = json_decode((string) $row['mconfig_data'], true);
        if (!is_array($decoded)) {
            return $default;
        }
        $threshold = array_key_exists('auto_complete_threshold_percent', $decoded)
            ? auto_complete_clamp_threshold($decoded['auto_complete_threshold_percent'])
            : 100;
        $metric = auto_complete_canonical_metric($decoded['auto_complete_metric'] ?? null);
        return ['threshold' => $threshold, 'metric' => $metric];
    }
}

if (!function_exists('autoCompleteCountEngagement')) {
    /**
     * Phase 3.15: count "engaged" recipients per the selected metric.
     * Engagement = at least one of the signals enabled by the metric.
     * For 'opens_clicks' we cross-reference tb_data_webpage_visit and for
     * 'opens_clicks_submits' tb_data_webform_submit too, joining by RID
     * (the same RID is set on both the mail row and any web-tracker hit).
     *
     * @return array{0: int, 1: int} [engaged, total]
     */
    function autoCompleteCountEngagement(
        mysqli $conn,
        string $campaignId,
        string $metric = 'opens'
    ): array {
        $signals = auto_complete_signals_for_metric($metric);

        // Collect every recipient RID + whether they opened the mail.
        $stmt = $conn->prepare(
            "SELECT rid,
                    CASE WHEN mail_open_times IS NOT NULL
                          AND mail_open_times <> ''
                          AND mail_open_times <> '[]'
                         THEN 1 ELSE 0 END AS opened
             FROM tb_data_mailcamp_live
             WHERE campaign_id = ?"
        );
        if ($stmt === false) {
            return [0, 0];
        }
        $stmt->bind_param('s', $campaignId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rids = [];
        $opens = [];
        while ($res && $row = $res->fetch_assoc()) {
            $rid = (string) $row['rid'];
            $rids[$rid] = true;
            if ((int) $row['opened'] === 1) {
                $opens[$rid] = true;
            }
        }
        $stmt->close();
        $total = count($rids);
        if ($total === 0) {
            return [0, 0];
        }
        $engaged = $opens; // start from openers

        if ($signals['clicks']) {
            $hits = autoCompleteHitsForRids($conn, 'tb_data_webpage_visit', array_keys($rids));
            foreach ($hits as $rid) {
                $engaged[$rid] = true;
            }
        }
        if ($signals['submits']) {
            $hits = autoCompleteHitsForRids($conn, 'tb_data_webform_submit', array_keys($rids));
            foreach ($hits as $rid) {
                $engaged[$rid] = true;
            }
        }
        return [count($engaged), $total];
    }
}

if (!function_exists('autoCompleteHitsForRids')) {
    /**
     * @param string[] $rids
     * @return string[] unique rids present in $table
     */
    function autoCompleteHitsForRids(mysqli $conn, string $table, array $rids): array
    {
        if ($rids === []) {
            return [];
        }
        // Whitelist the table name — never operator-supplied here, but
        // belt-and-suspenders for the static analyzer.
        if (!in_array($table, ['tb_data_webpage_visit', 'tb_data_webform_submit'], true)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($rids), '?'));
        $types = str_repeat('s', count($rids));
        $sql = "SELECT DISTINCT rid FROM `$table` WHERE rid IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$rids);
        $stmt->execute();
        $res = $stmt->get_result();
        $hits = [];
        while ($res && $row = $res->fetch_assoc()) {
            $hits[] = (string) $row['rid'];
        }
        $stmt->close();
        return $hits;
    }
}

if (!function_exists('autoCompleteTransition')) {
    /**
     * Transition camp_status from 4 (tracking) to 3 (terminal/completed).
     * Guarded by a WHERE camp_status = 4 check so a parallel state change
     * (e.g. operator stop) doesn't get clobbered.
     */
    function autoCompleteTransition(mysqli $conn, string $campaignId): bool
    {
        $stmt = $conn->prepare(
            "UPDATE tb_core_mailcamp_list SET camp_status = 3 WHERE campaign_id = ? AND camp_status = 4"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('s', $campaignId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        return $changed;
    }
}
