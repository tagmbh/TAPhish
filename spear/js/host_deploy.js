/* FEATURE-R2.4 Phase 1: Push Landing to Host — client for hosted_pages_manager.
 * Loads deployable sources + allow-listed target hosts, deploys one to the other,
 * and shows the write result + HTTPS/cert verification. All values are escaped. */
(function () {
    'use strict';

    var MGR = 'manager/hosted_pages_manager';

    function post(body) {
        return fetch(MGR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.TAPHISH_CSRF || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function loadOptions() {
        post({ action_type: 'landing_deploy_targets' }).then(function (d) {
            if (!d || d.result !== 'success') {
                if (window.toastr) { toastr.error((d && d.error) || 'Could not load lists'); }
                return;
            }
            var $src = $('#hd_source').empty();
            (d.sources || []).forEach(function (s) {
                $('<option>').val(s.kind + '/' + s.name).text(s.kind + ' — ' + s.name).appendTo($src);
            });
            if (!(d.sources || []).length) { $('<option>').prop('disabled', true).text('(no landing sources found)').appendTo($src); }

            var $host = $('#hd_host').empty();
            (d.targets || []).forEach(function (h) { $('<option>').val(h).text(h).appendTo($host); });
            if (!(d.targets || []).length) { $('<option>').prop('disabled', true).text('(no look-alike hosts found)').appendTo($host); }
        });
    }

    function deploy() {
        var raw = $('#hd_source').val() || '';
        var slash = raw.indexOf('/');
        var host = $('#hd_host').val() || '';
        if (slash < 1 || !host) {
            if (window.toastr) { toastr.warning('Pick a source and a target host'); }
            return;
        }
        var $btn = $('#hd_deploy').prop('disabled', true);
        $('#hd_result').html('<span class="text-muted">Deploying…</span>');
        post({
            action_type:  'landing_deploy',
            source_kind:  raw.slice(0, slash),
            source_name:  raw.slice(slash + 1),
            host:         host
        }).then(function (d) {
            $btn.prop('disabled', false);
            if (!d || d.result !== 'success') {
                $('#hd_result').html('<div class="alert alert-danger">Deploy failed: ' + esc(d && d.error) + '</div>');
                return;
            }
            var v = d.verify || {};
            var httpOk = v.http_code >= 200 && v.http_code < 400;
            var allOk = httpOk && v.ssl_ok;
            $('#hd_result').html(
                '<div class="alert ' + (allOk ? 'alert-success' : 'alert-warning') + '">' +
                'Deployed to <strong>' + esc(d.host) + '</strong> — ' +
                '<a href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(d.url) + '</a><br>' +
                'Files: ' + esc((d.written || []).join(', ')) + '<br>' +
                'Verify: HTTP ' + esc(v.http_code) + ', certificate ' + (v.ssl_ok ? 'OK' : 'NOT verified') +
                (allOk ? '' : ' — check the host/cert.') +
                '</div>'
            );
        }).catch(function () {
            $btn.prop('disabled', false);
            $('#hd_result').html('<div class="alert alert-danger">Request failed.</div>');
        });
    }

    $(function () {
        $('#hd_deploy').on('click', deploy);
        $('#hd_reload').on('click', loadOptions);
        loadOptions();
    });
})();
