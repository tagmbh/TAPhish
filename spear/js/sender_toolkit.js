/* Phase 3.41: sender toolkit — homoglyph + DMARC look-up. */

(function () {
    'use strict';

    function post(payload) {
        return $.ajax({
            url: 'manager/userlist_campaignlist_mailtemplate_manager',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }

    function esc(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    function renderHomoglyph(data) {
        var $r = $('#homoglyph_results').empty();
        var checked = !!(data && data.validated);
        $('#homoglyph_check_note').toggle(checked);
        if (!data || data.result !== 'success' || !data.candidates || !data.candidates.length) {
            $r.html('<div class="text-muted small">No ' + (checked ? 'valid registrable ' : '') + 'candidates returned.</div>');
            return;
        }
        var $table = $('<table class="table table-sm table-striped"></table>');
        $table.append('<thead><tr><th>Domain</th>' +
            (checked ? '<th>Registrable form</th>' : '') +
            '<th style="width:90px;">Kind</th><th style="width:60px;">Score</th></tr></thead>');
        var $body = $('<tbody></tbody>');
        data.candidates.forEach(function (c) {
            var $row = $('<tr></tr>');
            $row.append('<td style="font-family:var(--t-mono);">' + esc(c.domain) + '</td>');
            if (checked) {
                var idna = (c.name_idna && c.name_idna !== c.domain)
                    ? '<code>' + esc(c.name_idna) + '</code>'
                    : '<span class="text-muted">same</span>';
                $row.append('<td style="font-family:var(--t-mono);">' + idna + '</td>');
            }
            $row.append('<td><span class="t-pretext-tag">' + esc(c.kind) + '</span></td>');
            $row.append('<td>' + esc(c.score) + '</td>');
            $body.append($row);
        });
        $table.append($body);
        $r.append($table);
    }

    function renderHomoglyphChecked(data) {
        if (data) data.validated = true;
        renderHomoglyph(data);
    }

    function renderDmarc(data) {
        var $r = $('#dmarc_results').empty();
        if (!data || data.result !== 'success' || !data.posture || !data.posture.ok) {
            $r.html('<div class="text-warning small">Could not look up that domain.</div>');
            return;
        }
        var p = data.posture;
        var verdictColor = {
            'hardened':            'danger',
            'partially-hardened':  'warning',
            'monitoring':          'warning',
            'spf-only-strict':     'warning',
            'wide-open':           'success',
            'unknown':             'secondary'
        }[p.verdict] || 'secondary';

        var html = '';
        html += '<div class="alert alert-' + verdictColor + ' mt-2">';
        html += '<strong>' + esc(p.verdict) + '</strong> &middot; ' + esc(p.recommendation);
        html += '</div>';
        html += '<dl class="row small">';
        html += '<dt class="col-sm-4">SPF</dt><dd class="col-sm-8" style="font-family:var(--t-mono);">' + (esc(p.spf_raw) || '<span class="text-muted">none</span>') + '</dd>';
        html += '<dt class="col-sm-4">DMARC</dt><dd class="col-sm-8" style="font-family:var(--t-mono);">' + (esc(p.dmarc_raw) || '<span class="text-muted">none</span>') + '</dd>';
        html += '<dt class="col-sm-4">MX</dt><dd class="col-sm-8" style="font-family:var(--t-mono);">';
        html += (p.mx_hosts && p.mx_hosts.length ? p.mx_hosts.map(esc).join('<br>') : '<span class="text-muted">none</span>');
        html += '</dd>';
        html += '</dl>';
        $r.html(html);
    }

    function trigger(inputId, btnId, action, render) {
        $('#' + btnId).on('click', function () {
            var domain = $('#' + inputId).val().trim();
            if (!domain) { return; }
            $('#' + btnId).prop('disabled', true);
            post({ action_type: action, domain: domain })
                .done(render)
                .fail(function (xhr) {
                    toastr.error('Request failed (HTTP ' + xhr.status + ').');
                })
                .always(function () { $('#' + btnId).prop('disabled', false); });
        });
        $('#' + inputId).on('keypress', function (e) {
            if (e.which === 13) { $('#' + btnId).click(); }
        });
    }

    $(function () {
        trigger('homoglyph_input', 'btn_homoglyph', 'homoglyph_candidates', renderHomoglyph);
        trigger('homoglyph_input', 'btn_homoglyph_check', 'homoglyph_check_candidates', renderHomoglyphChecked);
        trigger('dmarc_input',     'btn_dmarc',     'email_posture_lookup', renderDmarc);
    });
})();
