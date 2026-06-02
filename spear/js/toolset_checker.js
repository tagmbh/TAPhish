/* Phase 3.43h: Toolset Checker. */

(function () {
    'use strict';

    var DISPATCHER = 'manager/userlist_campaignlist_mailtemplate_manager';

    function post(payload) {
        return $.ajax({
            url: DISPATCHER,
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }

    function esc(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    function badgeFor(status) {
        return ({
            'ok':    'success',
            'warn':  'warning',
            'error': 'danger'
        })[status] || 'secondary';
    }

    function renderVerdict(summary) {
        var verdict = summary.verdict;
        var counts = summary.counts || {};
        var badge = ({
            'ready':   'success',
            'caution': 'warning',
            'blocked': 'danger'
        })[verdict] || 'secondary';
        var msg = ({
            'ready':   'All systems go. You can launch a campaign.',
            'caution': 'Warnings present — review before send.',
            'blocked': 'One or more hard failures — fix before send.'
        })[verdict] || '';
        $('#ts_verdict').html(
            '<div class="alert alert-' + badge + '">'
            + '<strong>Verdict: ' + esc(verdict) + '.</strong> ' + esc(msg)
            + '<div class="small mt-1">'
            + (counts.ok || 0)    + ' ok &middot; '
            + (counts.warn || 0)  + ' warn &middot; '
            + (counts.error || 0) + ' error</div>'
            + '</div>'
        );
    }

    function renderResults(results) {
        var $tbl = $('<table class="table table-sm table-striped"><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody></tbody></table>');
        var $body = $tbl.find('tbody');
        results.forEach(function (r) {
            $body.append(
                $('<tr>')
                    .append($('<td>').text(r.label || r.key || ''))
                    .append($('<td>').append(
                        $('<span class="badge"></span>')
                            .addClass('badge-' + badgeFor(r.status))
                            .text(r.status || 'unknown')
                    ))
                    .append($('<td class="small text-muted">').text(r.detail || ''))
            );
        });
        $('#ts_results').empty().append($tbl);
    }

    function run() {
        $('#ts_verdict').html('<div class="alert alert-info">Running checks…</div>');
        $('#ts_results').empty();
        post({ action_type: 'run_toolset_checks', sender_domain: ($('#ts_sender').val() || '').trim() })
            .done(function (res) {
                if (!res || res.result !== 'success') {
                    $('#ts_verdict').html('<div class="alert alert-danger">Could not run checks.</div>');
                    return;
                }
                var report = res.report || {};
                renderVerdict(report.summary || {});
                renderResults(report.results || []);
            })
            .fail(function () {
                $('#ts_verdict').html('<div class="alert alert-danger">Request failed.</div>');
            });
    }

    $(function () {
        $('#ts_run').on('click', run);
        $('#ts_sender').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); run(); }
        });
    });
})();
