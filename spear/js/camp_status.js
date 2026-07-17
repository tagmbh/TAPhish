// Canonical campaign-status decoder — the single source of truth for the
// camp_status integer codes the scheduler SETS (see spear/core/mail_campaign_cron.php
// and campaign_auto_complete.php):
//
//   0  draft / not scheduled      (Inactive)
//   1  scheduled / armed          (SniperPhish_Manager fires camp_status=1)
//   2  in-progress                (mails sending + tracking active)
//   3  completed / stopped        (manual stop, failure-auto-pause, OR 4→3 auto-complete)
//   4  mail sent, tracking phase  (phishing window still open; auto-completes to 3)
//   5  tz-deferred                (recipients outside their local send window; resumed)
//   (6 is reserved — no code path sets it today.)
//
// Replaces the two divergent inline decoders that used to live in
// mail_campaign.js (a switch with NO case for 5 → "undefined" badge) and
// engagement_view.js. NOTE: code 3 conflates completed / stopped / error-paused
// because the data model has no distinct error state — the failure case is
// surfaced separately by the send watchdog/Telegram alerts. Splitting 3 into a
// dedicated error status is tracked in the UI/IA redesign backlog.
(function (global) {
    var MAP = {
        0: { key: 'draft',       label: 'Inactive',        cls: 'badge-dark',      icon: 'mdi-alert' },
        1: { key: 'scheduled',   label: 'Scheduled',       cls: 'badge-warning',   icon: 'mdi-timer' },
        2: { key: 'in_progress', label: 'In-progress',     cls: 'badge-primary',   icon: 'mdi-email' },
        3: { key: 'completed',   label: 'Completed',       cls: 'badge-success',   icon: 'mdi-check' },
        4: { key: 'tracking',    label: 'Sent · Tracking', cls: 'badge-info',      icon: 'mdi-fish' },
        5: { key: 'deferred',    label: 'Deferred',        cls: 'badge-secondary', icon: 'mdi-timer-sand' }
    };

    function meta(s) {
        return MAP[parseInt(s, 10)]
            || { key: 'unknown', label: 'Status ' + s, cls: 'badge-light', icon: 'mdi-help-circle' };
    }

    function escapeAttr(v) {
        return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    global.campStatus = {
        meta: meta,
        // Short text label for compact lists (e.g. the engagement view).
        label: function (s) { return meta(s).label; },
        // Full pill badge HTML for the campaign list.
        badge: function (s) {
            var m = meta(s);
            var label = escapeAttr(m.label);
            return '<span class="badge badge-pill ' + m.cls + '" data-toggle="tooltip" title="'
                + label + '"><i class="mdi ' + m.icon + '"></i> ' + label + '</span>';
        }
    };
})(window);
