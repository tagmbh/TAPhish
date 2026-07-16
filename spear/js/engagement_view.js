/* Phase 3.45b: Engagement View — picker, header, campaigns, status transitions. */

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

    function statusBadgeClass(status) {
        return ({
            'draft':     'badge-secondary',
            'live':      'badge-info',
            'completed': 'badge-success',
            'cancelled': 'badge-danger'
        })[status] || 'badge-secondary';
    }

    // Delegate to the canonical decoder (js/camp_status.js) so the campaign
    // status labels can't diverge from the campaign-list view again.
    function campStatusLabel(s) {
        return window.campStatus ? window.campStatus.label(s) : ('status ' + s);
    }

    function renderPicker(engagements) {
        var $body = $('#eng_picker_table tbody').empty();
        if (!engagements.length) {
            $body.append('<tr><td colspan="5" class="text-muted">No engagements yet. Start one from <a href="QuickStart">Quick Start</a>.</td></tr>');
            return;
        }
        engagements.forEach(function (e) {
            var $tr = $('<tr>');
            $tr.append($('<td>').append($('<code>').text(e.slug)));
            $tr.append($('<td>').addClass('small').text((e.start_at || '—') + ' → ' + (e.end_at || '—')));
            $tr.append($('<td>').addClass('small text-muted').text((e.scope_allowlist || []).slice(0, 3).join(', ')));
            $tr.append($('<td>').append(
                $('<span>').addClass('badge ' + statusBadgeClass(e.status)).text(e.status || 'draft')
            ));
            // Phase 3.56: resumable drafts deep-link back into the wizard.
            var resumable = (e.status || 'draft') === 'draft' && ((parseInt(e.wizard_step, 10) || 1) < 7);
            $tr.append($('<td>').append(
                resumable
                    ? $('<a class="btn btn-sm btn-info">')
                        .attr('href', 'QuickStart?engagement_id=' + e.id)
                        .html('<i class="fa fa-play"></i> Continue setup')
                    : $('<a class="btn btn-sm btn-info">')
                        .attr('href', 'EngagementView?engagement_id=' + e.id)
                        .text('Open')
            ));
            $body.append($tr);
        });
    }

    function renderHeader(eng) {
        $('#eng_name').text(eng.name);
        $('#eng_status_badge')
            .text(eng.status || 'draft')
            .removeClass('badge-secondary badge-info badge-success badge-danger')
            .addClass(statusBadgeClass(eng.status));
        var meta = [
            (eng.target_org ? 'Target: ' + eng.target_org : ''),
            (eng.start_at ? 'Start: ' + eng.start_at : ''),
            (eng.end_at ? 'End: ' + eng.end_at : ''),
            (eng.created_by ? 'Created by: ' + eng.created_by : ''),
            (eng.created_at ? 'Created: ' + eng.created_at : '')
        ].filter(Boolean).join(' · ');
        $('#eng_meta').text(meta);
        var scope = (eng.scope_allowlist || []).map(esc).join(', ');
        $('#eng_scope').html('<strong>Authorised scope:</strong> ' + (scope || '—'));
        // Slug stamped for transition buttons.
        $('#eng_transition_btns').data('current-status', eng.status || 'draft');
        // Phase 3.56: a draft engagement whose wizard hasn't reached Step 7
        // gets a deep-link that resumes QuickStart at the saved step.
        var $cont = $('#eng_continue_setup').empty();
        var step = parseInt(eng.wizard_step, 10) || 1;
        if ((eng.status || 'draft') === 'draft' && step < 7) {
            $('<a class="btn btn-sm btn-info">')
                .attr('href', 'QuickStart?engagement_id=' + (eng.id || $('#eng_view_id').val()))
                .html('<i class="fa fa-play"></i> Continue setup <span class="text-white-50">(step ' + step + ' of 7)</span>')
                .appendTo($cont);
        }
    }

    function renderCampaigns(campaigns) {
        var $body = $('#eng_campaigns_table tbody').empty();
        if (!campaigns.length) {
            $body.append('<tr><td colspan="4" class="text-muted">No campaigns linked yet. Run a campaign from <a href="QuickStart?engagement_id=' + ($('#eng_view_id').val()) + '">Quick Start</a> with this engagement selected.</td></tr>');
            return;
        }
        campaigns.forEach(function (c) {
            var $tr = $('<tr>');
            $tr.append($('<td>').append(
                $('<a>').attr('href', 'MailCmpDashboard?campaign_id=' + encodeURIComponent(c.campaign_id))
                    .text(c.campaign_name || c.campaign_id)
            ));
            $tr.append($('<td>').addClass('small').text(c.scheduled_time || '—'));
            $tr.append($('<td>').append(
                $('<span>').addClass('badge badge-secondary').text(campStatusLabel(c.camp_status))
            ));
            $tr.append($('<td>').append(
                $('<a class="btn btn-sm btn-outline-info">')
                    .attr('href', 'MailCmpDashboard?campaign_id=' + encodeURIComponent(c.campaign_id))
                    .text('Dashboard')
            ));
            $body.append($tr);
        });
    }

    function loadView(id) {
        post({ action_type: 'get_engagement_view', engagement_id: id })
            .done(function (res) {
                if (!res || res.result !== 'success') {
                    $('#eng_header_wrap').hide();
                    $('#eng_campaigns_wrap').hide();
                    if (window.toastr) toastr.error((res && res.error) || 'Could not load engagement');
                    return;
                }
                renderHeader(res.engagement);
                renderCampaigns(res.campaigns || []);
                $('#eng_header_wrap').show();
                $('#eng_campaigns_wrap').show();
            })
            .fail(function () {
                if (window.toastr) toastr.error('Request failed');
            });
    }

    function loadPicker() {
        post({ action_type: 'list_engagements' })
            .done(function (res) {
                if (res && res.result === 'success') renderPicker(res.engagements || []);
                else renderPicker([]);
            })
            .fail(function () { renderPicker([]); });
        $('#eng_picker_wrap').show();
    }

    function bindTransitions(id) {
        $('#eng_transition_btns button').on('click', function () {
            var to = $(this).data('to');
            var from = $('#eng_transition_btns').data('current-status');
            if (from === to) {
                if (window.toastr) toastr.info('Already in that status');
                return;
            }
            post({ action_type: 'engagement_transition_status', engagement_id: id, from: from, to: to })
                .done(function (res) {
                    if (res && res.result === 'success') {
                        if (window.toastr) toastr.success('Status updated to ' + res.status);
                        loadView(id);
                    } else {
                        if (window.toastr) toastr.error((res && res.error) || 'Transition rejected');
                    }
                })
                .fail(function () {
                    if (window.toastr) toastr.error('Request failed');
                });
        });
    }

    function bindDelete(id) {
        $('#btn_delete_engagement').on('click', function () {
            var name = $('#eng_name').text() || ('#' + id);
            if (!window.confirm('Delete engagement "' + name + '"?\n\nLinked campaigns are kept but unlinked. This cannot be undone.')) {
                return;
            }
            var $btn = $(this).prop('disabled', true);
            post({ action_type: 'delete_engagement', engagement_id: id })
                .done(function (res) {
                    if (res && res.result === 'success') {
                        if (window.toastr) {
                            toastr.success('Engagement deleted' +
                                (res.unlinked ? ' (' + res.unlinked + ' campaign(s) unlinked)' : ''));
                        }
                        // Drop back to the picker view.
                        setTimeout(function () { location.href = 'EngagementView'; }, 600);
                    } else {
                        $btn.prop('disabled', false);
                        if (window.toastr) toastr.error((res && res.error) || 'Delete failed');
                    }
                })
                .fail(function () {
                    $btn.prop('disabled', false);
                    if (window.toastr) toastr.error('Request failed');
                });
        });
    }

    $(function () {
        var id = parseInt($('#eng_view_id').val(), 10) || 0;
        if (id > 0) {
            loadView(id);
            bindTransitions(id);
            bindDelete(id);
            $('#btn_refresh_eng_view').on('click', function () { loadView(id); });
        } else {
            loadPicker();
        }
    });
})();
