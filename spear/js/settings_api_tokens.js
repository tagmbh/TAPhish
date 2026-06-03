// Phase 3.48 (RBAC): self-service personal API tokens. Every request acts on
// the caller's OWN tokens — the server resolves the user from the session.
var dt_token_list;
var g_revokeTokenId = '';

loadTableTokenList();

function fmtTokenTime(epochSeconds) {
    if (!epochSeconds) return 'Never';
    return moment.unix(epochSeconds).format('DD-MM-YYYY hh:mm A');
}

function mintTokenAction(e) {
    var label = $("#tb_token_label").val().trim();
    if (label === '') {
        $("#tb_token_label").addClass("is-invalid");
        return;
    }
    $("#tb_token_label").removeClass("is-invalid");

    enableDisableMe(e);
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: "mint_api_token", label: label })
    }).done(function (response) {
        if (response.result === "success") {
            $("#tb_token_label").val('');
            $("#token_reveal_value").val(response.token);
            $("#token_reveal_ack").prop('checked', false);
            $("#token_reveal_close").prop('disabled', true);
            $("#ModalTokenReveal").modal('show');
            loadTableTokenList();
        } else {
            toastr.error('', response.error || 'Could not create token.');
        }
        enableDisableMe(e);
    }).fail(function (xhr) {
        toastr.error('', 'Request failed (HTTP ' + xhr.status + ').');
        enableDisableMe(e);
    });
}

function copyRevealedToken() {
    var text = $("#token_reveal_value").val();
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(
            function () { toastr.success('', 'Copied.'); },
            function () { toastr.error('', 'Copy failed — select manually.'); }
        );
    } else {
        $("#token_reveal_value").select();
        toastr.warning('', 'Clipboard API unavailable — select and copy manually.');
    }
}

function promptRevokeToken(id) {
    g_revokeTokenId = id;
    $("#ModalTokenRevoke").modal('toggle');
}

function revokeTokenAction() {
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: "revoke_api_token", id: g_revokeTokenId })
    }).done(function (response) {
        $("#ModalTokenRevoke").modal('toggle');
        if (response.result === "success") {
            toastr.success('', 'Token revoked.');
            loadTableTokenList();
        } else {
            toastr.error('', response.error || 'Revoke failed.');
        }
    }).fail(function () {
        $("#ModalTokenRevoke").modal('toggle');
        toastr.error('', 'Revoke request failed.');
    });
}

function loadTableTokenList() {
    try { dt_token_list.destroy(); } catch (err) {}
    $('#table_token_list tbody > tr').remove();

    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: "list_api_tokens" })
    }).done(function (data) {
        if (data && data.result === "success" && data.tokens) {
            $.each(data.tokens, function (key, t) {
                var revoked = !!t.revoked_at;
                var status = revoked
                    ? '<span class="badge badge-secondary">Revoked</span>'
                    : '<span class="badge badge-success">Active</span>';
                var actions = revoked
                    ? ''
                    : '<button type="button" class="btn btn-danger btn-sm" data-toggle="tooltip" data-placement="top" title="Revoke" onclick="promptRevokeToken(\'' + t.id + '\')"><i class="mdi mdi-cancel"></i></button>';
                var created = fmtTokenTime(t.created_at);
                var used = fmtTokenTime(t.last_used_at);
                $("#table_token_list tbody").append(
                    "<tr><td></td><td>" + $('<div>').text(t.label).html() + "</td>" +
                    "<td data-order=\"" + (t.created_at || 0) + "\">" + created + "</td>" +
                    "<td data-order=\"" + (t.last_used_at || 0) + "\">" + used + "</td>" +
                    "<td>" + status + "</td><td>" + actions + "</td></tr>"
                );
            });
        }

        dt_token_list = $('#table_token_list').DataTable({
            "bDestroy": true,
            'pageLength': 20,
            'lengthMenu': [[20, 50, 100, -1], [20, 50, 100, 'All']],
            "preDrawCallback": function () { $('#table_token_list tbody').hide(); },
            "drawCallback": function () {
                $('#table_token_list tbody').fadeIn(500);
                $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });
            }
        });

        dt_token_list.on('order.dt search.dt', function () {
            dt_token_list.column(0, { search: 'applied', order: 'applied' }).nodes().each(function (cell, i) {
                cell.innerHTML = i + 1;
            });
        }).draw();
    });
}
