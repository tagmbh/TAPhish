/* Phase 3.33: Home dashboard data fetch + render. */

(function () {
    'use strict';

    function post(payload) {
        return $.ajax({
            url: 'manager/home_manager',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }

    function pct(n) {
        if (n == null || isNaN(n)) return '—';
        return Math.round(n * 10) / 10 + '%';
    }

    function fmtTrend(deltaPp, goodIsUp) {
        if (deltaPp == null || isNaN(deltaPp)) return null;
        var arrow = deltaPp >= 0 ? '▲' : '▼';
        var cls   = (deltaPp >= 0) === goodIsUp ? 'is-up' : 'is-down';
        return { text: arrow + ' ' + Math.abs(Math.round(deltaPp * 10) / 10) + ' pp', cls: cls };
    }

    function renderMetrics(data) {
        // home_manager.php / getHomeGraphData returns:
        //   { campaign_info: { webtracker:[], mailcamp:[], quicktracker:[] }, ... }
        // No precomputed open/click rates exist server-side yet (Phase 3.33 scope),
        // so we surface the campaign count and leave rate tiles as '—'.
        var ci    = (data && data.campaign_info) || {};
        var camps = (ci.mailcamp && ci.mailcamp.length) || 0;

        var openR  = data && data.metrics && data.metrics.open_rate;
        var clickR = data && data.metrics && data.metrics.click_rate;
        var openD  = data && data.metrics && data.metrics.open_rate_delta_pp;
        var clickD = data && data.metrics && data.metrics.click_rate_delta_pp;

        $('#m_active_campaigns').text(camps);
        $('#m_open_rate').text(pct(openR));
        $('#m_click_rate').text(pct(clickR));

        var t1 = fmtTrend(openD, true);
        var t2 = fmtTrend(clickD, true);
        if (t1) $('#m_open_rate_trend').text(t1.text).removeClass('is-up is-down').addClass(t1.cls);
        if (t2) $('#m_click_rate_trend').text(t2.text).removeClass('is-up is-down').addClass(t2.cls);
    }

    function renderCron(data) {
        // home_manager.php / checkSniperPhishProcess returns { result: true|false }.
        // No pid is exposed by the PHP layer; treat truthy result as "running".
        var dot = $('#m_cron_dot');
        var st  = $('#m_cron_status');
        var sb  = $('#sidebar_cron_status');
        // Strip only the state classes we own — don't clobber whatever
        // base class the page (or a future runtime hook) attached.
        var DOT_STATES = 'is-success is-warn is-danger';
        var SB_STATES  = 'is-ready is-warn is-down';
        if (data && data.result === true) {
            dot.text('●').removeClass(DOT_STATES).addClass('is-success');
            st.text('Running');
            sb.text('● ready').removeClass(SB_STATES).addClass('is-ready');
        } else {
            dot.text('●').removeClass(DOT_STATES).addClass('is-warn');
            st.text('Stopped');
            sb.text('● stopped').removeClass(SB_STATES).addClass('is-warn');
        }
    }

    function renderActivity(data) {
        var $body = $('#t_activity_body');
        $body.empty();
        if (!data || data.result !== 'success' || !data.entries || data.entries.length === 0) {
            $body.append('<div class="t-activity-empty">No activity yet — start a campaign or change a setting.</div>');
            return;
        }
        data.entries.forEach(function (e) {
            var sev = e.severity || 'ok';
            var $row = $('<div/>', { class: 't-activity-row' });
            $row.append($('<span/>', { class: 't-activity-time', text: e.time || '' }));
            $row.append($('<span/>', { class: 't-activity-kind', text: e.kind || 'SYS' }));
            $row.append($('<span/>', { class: 't-activity-msg',  text: e.message || '' }));
            $row.append($('<span/>', { class: 't-activity-sev is-' + sev, text: sev }));
            $body.append($row);
        });
    }

    $(function () {
        post({ action_type: 'get_home_graphs_data' })
            .done(renderMetrics)
            .fail(function () {
                $('.t-metric-num').text('—');
            });

        post({ action_type: 'check_process' })
            .done(renderCron)
            .fail(function () { renderCron(null); });

        post({ action_type: 'get_recent_log_entries', limit: 10 })
            .done(renderActivity)
            .fail(function () {
                $('#t_activity_body').empty().append(
                    '<div class="t-activity-empty">Activity feed unavailable.</div>'
                );
            });
    });
})();
