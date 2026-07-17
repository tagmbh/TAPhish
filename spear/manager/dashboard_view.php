<?php

/**
 * Phase 1 — dashboard single-page fold helpers.
 *
 * The web dashboard (WebMailCmpDashboard) is the superset and becomes the one
 * "Campaign Dashboard": the email side always renders; the web-tracker side
 * renders only when a tracker is selected AND the "Show web tracker" toggle is
 * on. The client mirrors this pure decision so a campaign can be viewed with no
 * tracker at all (== the retired email-only dashboard).
 */

if (!function_exists('taphish_dashboard_sections')) {
    /**
     * @param mixed $hasTracker truthy when a web tracker is selected (non-empty id)
     * @param mixed $showWeb    truthy when the "Show web tracker" toggle is on
     * @return array{email: bool, web: bool}
     */
    function taphish_dashboard_sections($hasTracker, $showWeb): array
    {
        return [
            'email' => true,
            'web'   => (bool) $hasTracker && (bool) $showWeb,
        ];
    }
}
