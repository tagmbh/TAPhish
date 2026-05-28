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
            $threshold = autoCompleteResolveThreshold($conn, $row['campaign_data']);
            if ($threshold === 0) {
                continue;
            }
            [$opened, $total] = autoCompleteCountEngagement($conn, $row['campaign_id']);
            if (auto_complete_should_trigger($opened, $total, $threshold)) {
                if (autoCompleteTransition($conn, $row['campaign_id'])) {
                    $completed++;
                    if (function_exists('logIt')) {
                        logIt(
                            'Mail campaign auto-completed: ' . $opened . '/' . $total
                            . ' recipients engaged (threshold ' . $threshold . '%)',
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
        $mconfigId = $campaignData['mconfig_id'] ?? null;
        if (!is_string($mconfigId) || $mconfigId === '') {
            return 100;
        }
        $stmt = $conn->prepare("SELECT mconfig_data FROM tb_core_mailcamp_config WHERE mconfig_id = ?");
        if ($stmt === false) {
            return 100;
        }
        $stmt->bind_param('s', $mconfigId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return 100;
        }
        $decoded = json_decode((string) $row['mconfig_data'], true);
        if (!is_array($decoded)) {
            return 100;
        }
        if (!array_key_exists('auto_complete_threshold_percent', $decoded)) {
            return 100;
        }
        return auto_complete_clamp_threshold($decoded['auto_complete_threshold_percent']);
    }
}

if (!function_exists('autoCompleteCountEngagement')) {
    /**
     * @return array{0: int, 1: int} [opened, total]
     */
    function autoCompleteCountEngagement(mysqli $conn, string $campaignId): array
    {
        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN mail_open_times IS NOT NULL
                          AND mail_open_times <> ''
                          AND mail_open_times <> '[]'
                         THEN 1 ELSE 0 END) AS opened
             FROM tb_data_mailcamp_live
             WHERE campaign_id = ?"
        );
        if ($stmt === false) {
            return [0, 0];
        }
        $stmt->bind_param('s', $campaignId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return [0, 0];
        }
        return [(int) ($row['opened'] ?? 0), (int) ($row['total'] ?? 0)];
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
