// Phase 3.52 task 6+8: hooked-browsers widget.
//
// Polls home_manager?action_type=beef_list_hooks every 30s while the
// tab is visible; stops while hidden so we don't burn BeEF API quota
// when the operator isn't looking. Renders in-scope hooks neutral,
// out-of-scope hooks with a yellow chip + the reason. Degrades to a
// help-string on not_configured / unreachable / auth_failed so the
// operator always sees actionable text.
(function ($) {
    'use strict';

    var POLL_MS = 30 * 1000;
    var timer = null;

    function post(payload) {
        return $.ajax({
            url: 'manager/home_manager',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    function renderState(state, err) {
        var helpMap = {
            not_configured: '<span class="text-muted">Not configured. ' +
                            '<a href="SettingsGeneral">Add credentials</a>' +
                            ' under Settings → General to enable the dashboard.</span>',
            unreachable:    '<span class="text-warning">BeEF server unreachable. ' +
                            'Check that it\'s running and the URL is reachable from this host.</span>',
            auth_failed:    '<span class="text-warning">BeEF rejected the stored credentials. ' +
                            '<a href="SettingsGeneral">Re-save</a> and try Test.</span>'
        };
        return helpMap[state] || ('<span class="text-warning">' + esc(err || 'BeEF unavailable.') + '</span>');
    }

    function renderHooks(hooks) {
        if (!hooks.length) {
            return '<div class="t-activity-empty">No active hooks. ' +
                   'Cloned pages with the BeEF hook on will appear here as visitors land.</div>';
        }
        var rows = hooks.map(function (h) {
            var scopeChip = h.in_scope
                ? '<span class="badge badge-success">in-scope</span>'
                : '<span class="badge badge-warning" title="' + esc(h.scope_reason) + '">out-of-scope</span>';
            return '<div class="t-activity-row" data-kind="BEEF">' +
                '<div class="t-activity-time">id=' + esc(h.id) + '</div>' +
                '<div class="t-activity-kind">' + scopeChip + '</div>' +
                '<div class="t-activity-msg"><code>' + esc(h.domain || '—') + '</code> · ' +
                    esc(h.browser || '') + (h.os ? ' / ' + esc(h.os) : '') + '</div>' +
                '<div class="t-activity-sev">' + esc(h.ip || '') + '</div>' +
                '</div>';
        });
        return rows.join('');
    }

    function refresh() {
        post({ action_type: 'beef_list_hooks' }).done(function (d) {
            if (!d || d.result !== 'success') {
                $('#t_beef_count').text('—');
                $('#t_beef_meta').text('error');
                $('#t_beef_body').html('<div class="t-activity-empty text-warning">Request failed.</div>');
                return;
            }
            if (d.state !== 'ok') {
                $('#t_beef_count').text('—');
                $('#t_beef_meta').text(d.state.replace('_', ' '));
                // Server now returns d.error (renamed from d.err in the
                // review sweep); keep the d.err fallback for old caches.
                $('#t_beef_body').html('<div class="t-activity-empty">' + renderState(d.state, d.error || d.err) + '</div>');
                return;
            }
            var hooks = d.hooks || [];
            var outOfScope = hooks.filter(function (h) { return !h.in_scope; }).length;
            $('#t_beef_count').text(hooks.length);
            $('#t_beef_meta').html('UTC · ' +
                (outOfScope > 0
                    ? '<span class="text-warning">' + outOfScope + ' out-of-scope</span>'
                    : 'all in-scope'));
            $('#t_beef_body').html(renderHooks(hooks));
        }).fail(function () {
            $('#t_beef_count').text('—');
            $('#t_beef_meta').text('error');
        });
    }

    function start() {
        if (timer !== null) return;
        // Second-pass review: claim the slot with a sentinel BEFORE
        // the ajax / setInterval call so a re-entrant start() during
        // the refresh's pending phase short-circuits at the guard
        // above instead of creating a duplicate interval.
        timer = -1;
        // Don't fire the initial poll on a background tab. The
        // visibilitychange handler restarts us when the tab becomes
        // visible.
        if (!document.hidden) refresh();
        timer = setInterval(function () {
            if (!document.hidden) refresh();
        }, POLL_MS);
    }

    function stop() {
        if (timer !== null) { clearInterval(timer); timer = null; }
    }

    $(function () {
        if (!$('#t_beef').length) return;  // page doesn't have the widget
        start();
        $(document).on('visibilitychange', function () {
            if (document.hidden) stop(); else start();
        });
    });
})(jQuery);
