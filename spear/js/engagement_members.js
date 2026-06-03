// Phase 3.48 (RBAC): per-engagement membership roster. Owners/super-admins
// manage members; the engagement_id comes from the page (?engagement_id=N).
var dt_member_list;
var g_removeMemberUser = '';

var ENG_ID = parseInt($('#eng_members_id').val(), 10) || 0;

if (ENG_ID > 0) {
    loadMembers();
}

function memberRoleSelectHtml(username, role) {
    var roles = [['owner', 'Owner'], ['member', 'Member'], ['read-only', 'Read-only']];
    if (!role) role = 'member';
    var opts = '';
    for (var i = 0; i < roles.length; i++) {
        var sel = (roles[i][0] === role) ? ' selected' : '';
        opts += '<option value="' + roles[i][0] + '"' + sel + '>' + roles[i][1] + '</option>';
    }
    return '<select class="form-control form-control-sm member-role-select" data-username="' +
        $('<div>').text(username).html() + '" onchange="setMemberRoleAction(this)">' + opts + '</select>';
}

function addMemberAction(e) {
    var username = $('#tb_member_username').val().trim();
    var role = $('#sel_member_role').val();
    if (username === '') {
        $('#tb_member_username').addClass('is-invalid');
        return;
    }
    $('#tb_member_username').removeClass('is-invalid');

    enableDisableMe(e);
    $.post({
        url: 'manager/userlist_campaignlist_mailtemplate_manager',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: 'add_engagement_member', engagement_id: ENG_ID, username: username, role: role })
    }).done(function (response) {
        if (response.result === 'success') {
            toastr.success('', 'Member added.');
            $('#tb_member_username').val('');
            loadMembers();
        } else {
            toastr.error('', response.error || 'Could not add member.');
        }
        enableDisableMe(e);
    }).fail(function (xhr) {
        toastr.error('', 'Request failed (HTTP ' + xhr.status + ').');
        enableDisableMe(e);
    });
}

function setMemberRoleAction(el) {
    var username = $(el).data('username');
    var role = $(el).val();
    $.post({
        url: 'manager/userlist_campaignlist_mailtemplate_manager',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: 'set_engagement_member_role', engagement_id: ENG_ID, username: username, role: role })
    }).done(function (response) {
        if (response.result === 'success') {
            toastr.success('', 'Role updated.');
        } else {
            toastr.error('', response.error || 'Role update failed.');
            loadMembers();
        }
    }).fail(function () {
        toastr.error('', 'Role update request failed.');
        loadMembers();
    });
}

function promptRemoveMember(username) {
    g_removeMemberUser = username;
    $('#member_remove_name').text(username);
    $('#ModalMemberRemove').modal('toggle');
}

function removeMemberAction() {
    $.post({
        url: 'manager/userlist_campaignlist_mailtemplate_manager',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: 'remove_engagement_member', engagement_id: ENG_ID, username: g_removeMemberUser })
    }).done(function (response) {
        $('#ModalMemberRemove').modal('toggle');
        if (response.result === 'success') {
            toastr.success('', 'Member removed.');
            loadMembers();
        } else {
            toastr.error('', response.error || 'Could not remove member.');
        }
    }).fail(function () {
        $('#ModalMemberRemove').modal('toggle');
        toastr.error('', 'Remove request failed.');
    });
}

function loadMembers() {
    try { dt_member_list.destroy(); } catch (err) {}
    $('#table_member_list tbody > tr').remove();

    $.post({
        url: 'manager/userlist_campaignlist_mailtemplate_manager',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: 'list_engagement_members', engagement_id: ENG_ID })
    }).done(function (data) {
        if (data && data.result === 'success') {
            if (data.engagement && data.engagement.name) {
                $('#eng_members_name').text(data.engagement.name);
            }
            $.each(data.members || [], function (key, m) {
                var esc = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
                var actions = '<button type="button" class="btn btn-danger btn-sm" data-toggle="tooltip" data-placement="top" title="Remove" onclick="promptRemoveMember(\'' +
                    esc(m.username).replace(/'/g, "\\'") + '\')"><i class="mdi mdi-account-remove"></i></button>';
                $('#table_member_list tbody').append(
                    '<tr><td></td><td>' + esc(m.username) + '</td><td>' + esc(m.name) + '</td>' +
                    '<td>' + esc(m.global_role) + '</td>' +
                    '<td>' + memberRoleSelectHtml(m.username, m.engagement_role) + '</td>' +
                    '<td>' + actions + '</td></tr>'
                );
            });
        } else if (data && data.error) {
            toastr.error('', data.error);
        }

        dt_member_list = $('#table_member_list').DataTable({
            "bDestroy": true,
            'pageLength': 20,
            'lengthMenu': [[20, 50, 100, -1], [20, 50, 100, 'All']],
            "preDrawCallback": function () { $('#table_member_list tbody').hide(); },
            "drawCallback": function () {
                $('#table_member_list tbody').fadeIn(500);
                $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });
            }
        });

        dt_member_list.on('order.dt search.dt', function () {
            dt_member_list.column(0, { search: 'applied', order: 'applied' }).nodes().each(function (cell, i) {
                cell.innerHTML = i + 1;
            });
        }).draw();
    });
}
