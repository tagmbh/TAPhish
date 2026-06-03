// Phase 3.51: audit-log viewer front-end.
(function ($) {
    'use strict';
    var PAGE_SIZE = 100;
    var offset = 0;

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

    function readFilters() {
        return {
            action_type: 'audit_log_query',
            kind:        $('#f_kind').val(),
            severity:    $('#f_severity').val(),
            username:    $('#f_username').val(),
            search:      $('#f_search').val(),
            date_from:   $('#f_date_from').val(),
            date_to:     $('#f_date_to').val(),
            limit:       PAGE_SIZE,
            offset:      offset
        };
    }

    function sevBadge(sev) {
        var palette = { ok: 'badge-success', warn: 'badge-warning', error: 'badge-danger' };
        return '<span class="badge ' + (palette[sev] || 'badge-secondary') + '">' + esc(sev) + '</span>';
    }
    function kindBadge(k) {
        return '<span class="badge badge-light">' + esc(k) + '</span>';
    }

    function render(d) {
        var $body = $('#tbl_audit tbody').empty();
        if (!d.rows.length) {
            $body.append('<tr><td colspan="6" class="text-muted small text-center">No entries match.</td></tr>');
        } else {
            d.rows.forEach(function (r) {
                $body.append(
                    '<tr>' +
                    '<td class="small">' + esc(r.date) + '</td>' +
                    '<td class="small">' + esc(r.username) + '</td>' +
                    '<td class="small"><code>' + esc(r.ip) + '</code></td>' +
                    '<td>' + kindBadge(r.kind) + '</td>' +
                    '<td>' + sevBadge(r.severity) + '</td>' +
                    '<td class="small">' + esc(r.log) + '</td>' +
                    '</tr>'
                );
            });
        }
        $('#result_summary').text(d.rows.length + ' shown' + (d.has_more ? ' · more available' : ''));
        $('#btn_prev').prop('disabled', offset === 0);
        $('#btn_next').prop('disabled', !d.has_more);
        $('#page_indicator').text('Page ' + (Math.floor(offset / PAGE_SIZE) + 1));
    }

    function query() {
        $('#tbl_audit tbody').html('<tr><td colspan="6" class="text-muted small text-center">Loading…</td></tr>');
        post(readFilters())
            .done(function (d) {
                if (!d || d.result !== 'success') {
                    $('#tbl_audit tbody').html('<tr><td colspan="6" class="text-danger small">Query failed.</td></tr>');
                    return;
                }
                render(d);
            })
            .fail(function () {
                $('#tbl_audit tbody').html('<tr><td colspan="6" class="text-danger small">Request failed.</td></tr>');
            });
    }

    function buildCsvUrl() {
        var p = readFilters();
        delete p.action_type;
        delete p.limit;
        delete p.offset;
        var q = Object.keys(p).filter(function (k) { return p[k]; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(p[k]); })
            .join('&');
        return 'AuditLogExport.php' + (q ? '?' + q : '');
    }

    $(function () {
        $('#btn_query').on('click', function () { offset = 0; query(); });
        $('#btn_prev').on('click', function () {
            offset = Math.max(0, offset - PAGE_SIZE);
            query();
        });
        $('#btn_next').on('click', function () {
            offset += PAGE_SIZE;
            query();
        });
        $('#f_search, #f_username').on('keydown', function (e) {
            if (e.key === 'Enter') { offset = 0; query(); }
        });
        $('#btn_export_csv').on('click', function (e) {
            e.preventDefault();
            window.location.href = buildCsvUrl();
        });
        query();
    });
})(jQuery);
