/* Phase 3.43a: Quick-Start Wizard — Step 1 (engagement metadata). */

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

    function clearFieldErrors() {
        $('#frm_engagement .is-invalid').removeClass('is-invalid');
        $('#frm_engagement .invalid-feedback').remove();
    }

    function markFieldError(inputId, message) {
        var $input = $('#' + inputId);
        if (!$input.length) return;
        $input.addClass('is-invalid');
        $input.after($('<div class="invalid-feedback"></div>').text(message));
    }

    function readForm() {
        return {
            name: $('#eng_name').val(),
            target_org: $('#eng_org').val(),
            start_at: $('#eng_start').val(),
            end_at: $('#eng_end').val(),
            scope_allowlist: $('#eng_scope').val(),
            notes: $('#eng_notes').val()
        };
    }

    function renderRecent(rows) {
        var $body = $('#tb_engagements tbody').empty();
        if (!rows || !rows.length) {
            $body.append('<tr><td colspan="4" class="text-muted">No engagements yet.</td></tr>');
            return;
        }
        rows.forEach(function (e) {
            var scope = (e.scope_allowlist || []).slice(0, 3).join(', ');
            if ((e.scope_allowlist || []).length > 3) scope += ' …';
            var $tr = $('<tr>');
            $tr.append($('<td>').append($('<code>').text(e.slug)));
            $tr.append($('<td>').addClass('small').text(
                (e.start_at || '—') + ' → ' + (e.end_at || '—')
            ));
            $tr.append($('<td>').addClass('small text-muted').text(scope || '—'));
            $tr.append($('<td>').append(
                $('<span>').addClass('badge badge-secondary').text(e.status || 'draft')
            ));
            $body.append($tr);
        });
    }

    function refreshList() {
        post({ action_type: 'list_engagements' })
            .done(function (res) {
                if (res && res.result === 'success') renderRecent(res.engagements || []);
                else renderRecent([]);
            })
            .fail(function () { renderRecent([]); });
    }

    function onSubmit(e) {
        e.preventDefault();
        clearFieldErrors();
        $('#eng_result').empty();
        $('#btn_save_eng').prop('disabled', true);

        post({ action_type: 'save_engagement', payload: readForm() })
            .done(function (res) {
                if (res && res.result === 'success') {
                    $('#eng_result').html(
                        '<div class="alert alert-success">' +
                        '<strong>Saved.</strong> Slug: <code>' + esc(res.slug) + '</code>. ' +
                        'Next slice of the wizard (OSINT pre-check) will pick up automatically once Phase 3.43b ships. ' +
                        'For now, open the relevant tools manually:' +
                        '<ul class="mt-2 mb-0">' +
                        '<li><a href="SenderToolkit">Sender Toolkit</a> — SPF/DMARC posture + look-alike domain</li>' +
                        '<li><a href="PretextLibrary">Pretext Library</a> — pick + clone a starter</li>' +
                        '<li><a href="MailUserGroup">Mail User Group</a> — upload your recipient CSV</li>' +
                        '<li><a href="SiteCloner">Site Cloner</a> — clone a landing page</li>' +
                        '<li><a href="MailCampaignList?action=add&campaign=new">Email Campaign</a> — wire it all together + send</li>' +
                        '</ul>' +
                        '</div>'
                    );
                    $('#frm_engagement')[0].reset();
                    refreshList();
                    if (window.toastr) toastr.success('Engagement saved');
                } else if (res && res.errors) {
                    Object.keys(res.errors).forEach(function (field) {
                        var id = ({
                            name: 'eng_name',
                            target_org: 'eng_org',
                            start_at: 'eng_start',
                            end_at: 'eng_end',
                            scope_allowlist: 'eng_scope',
                            notes: 'eng_notes'
                        })[field] || field;
                        markFieldError(id, res.errors[field]);
                    });
                    $('#eng_result').html(
                        '<div class="alert alert-danger">Please fix the highlighted fields.</div>'
                    );
                } else {
                    $('#eng_result').html(
                        '<div class="alert alert-danger">' +
                        esc((res && res.error) || 'Could not save engagement.') +
                        '</div>'
                    );
                }
            })
            .fail(function (xhr) {
                $('#eng_result').html(
                    '<div class="alert alert-danger">Request failed (' + xhr.status + ').</div>'
                );
            })
            .always(function () {
                $('#btn_save_eng').prop('disabled', false);
            });
    }

    $(function () {
        $('#frm_engagement').on('submit', onSubmit);
        $('#btn_refresh_eng').on('click', refreshList);
        refreshList();
    });
})();
