var dt_user_list;
var g_modalValue='';
loadTableUserList();
getCurrentUser();

function getCurrentUser() {
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "get_current_user",
         })
    }).done(function (data) {
        if(!data.error){
            $('#lb_name').text(data.name);
            $('#lb_uname').text(data.username);
            $('#lb_mail').text(data.contact_mail);
            $('#lb_created_date').text(data.date);
            $('#user_dp').attr('src','/spear/images/users/' + data.dp_name + '.png');
            $('.pro-pic').attr('src','/spear/images/users/' + data.dp_name + '.png');
            $('#bt_edit_current_user').click(function(){
                prompModifyUser(data.id, data.name, data.username, data.contact_mail, data.dp_name);
            });
        }
        else
            toastr.error('', data.error);
    }); 
}

function addUserAction(e){
    var name = $("#tb_add_name").val().trim();
    var username = $("#tb_add_uname").val().trim();
    var mail = $("#tb_add_mail").val().trim();
    var new_pwd = $("#tb_add_pwd").val().trim();
    var confirm_pwd = $("#tb_add_confirm_pwd").val().trim();
    var dp_name =$('input[name="rb_add_dp"]:checked').val();
    var current_pwd = $("#tb_add_current_pwd").val().trim();
    var role = $("#tb_add_role").val();

    if(name == ''){
        $("#tb_add_name").addClass("is-invalid");
        return;
    } else
        $("#tb_add_name").removeClass("is-invalid");

    if(username == ''){
        $("#tb_add_uname").addClass("is-invalid");
        return;
    } else
        $("#tb_add_uname").removeClass("is-invalid");

    if(RegTest(mail, 'EMAIL') == false){
        $("#tb_add_mail").addClass("is-invalid");
        return;
    } else
        $("#tb_add_mail").removeClass("is-invalid");

    if (current_pwd == '') {
        $("#tb_add_current_pwd").addClass("is-invalid");
        return;
    } else
        $("#tb_add_current_pwd").removeClass("is-invalid");

    if (new_pwd == '' || confirm_pwd =='') {
        toastr.error('', 'New password can not be empty!');
        return;
    }
    
    if(!isPwdSecure(new_pwd, confirm_pwd, '#tb_add_pwd', '#tb_add_confirm_pwd'))
        return;

    enableDisableMe(e);
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "add_account",
            name: name,
            username: username,
            mail: mail,
            dp_name: dp_name,
            new_pwd: new_pwd,
            current_pwd: current_pwd,
            role: role,
        }),
    }).done(function (response) {
        if(response.result == "success"){
            toastr.success('', 'Information updated successfully!');
            $("#tb_add_new_pwd").val('');
            $("#tb_add_confirm_pwd").val('');
            $('#ModalAddUser').modal('toggle');
            loadTableUserList();
        }
        else
            toastr.error('', response.error);
        enableDisableMe(e);
    }); 
}

function modifyUserAction(e){
    var name = $("#tb_update_name").val().trim();
    var username = $("#tb_update_uname").val().trim();
    var mail = $("#tb_update_mail").val().trim();
	var current_pwd = $("#tb_update_current_pwd").val().trim();
	var new_pwd = $("#tb_update_new_pwd").val().trim();
	var confirm_pwd = $("#tb_update_confirm_pwd").val().trim();
    var dp_name =$('input[name="rb_update_dp"]:checked').val();

    if(name == ''){
        $("#tb_update_name").addClass("is-invalid");
        return;
    } else
        $("#tb_update_name").removeClass("is-invalid");

    if(RegTest(mail, 'EMAIL') == false){
        $("#tb_update_mail").addClass("is-invalid");
        return;
    } else
        $("#lb_update_mail").removeClass("is-invalid");

    if (current_pwd == '') {
        $("#tb_update_current_pwd").addClass("is-invalid");
        return;
    } else
        $("#tb_update_current_pwd").removeClass("is-invalid");

	if(!(new_pwd=='' && confirm_pwd==''))
        if(!isPwdSecure(new_pwd, confirm_pwd, '#tb_add_pwd', '#tb_add_confirm_pwd'))
            return;

    enableDisableMe(e);
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "modify_account",
            name: name,
            username: username,
            mail: mail,
            dp_name: dp_name,
            new_pwd: new_pwd,
            current_pwd: current_pwd,
         }),
    }).done(function (response) {
        if(response.result == "success"){ 
            toastr.success('', 'Information updated successfully!');   
            $("#tb_update_current_pwd").val('');
            $("#tb_update_new_pwd").val('');
            $("#tb_update_confirm_pwd").val('');
            $('#ModalModifyUser').modal('toggle');
            loadTableUserList();
            getCurrentUser();
        }
        else
            toastr.error('', response.error);
        enableDisableMe(e);
    }); 
}

function deleteAccountAction() {
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "delete_account",
            id: g_modalValue
         })
    }).done(function (response) {
        if(response.result == "success"){
            $('#modal_prompts').modal('toggle');
            toastr.success('', 'Deleted successfully!');
            $('#ModalUserDelete').modal('toggle');
            loadTableUserList();
        }
        else
            toastr.error('', response.error);
    }); 
}

function promptDeleteAccount(id) {
    g_modalValue = id;
    $('#ModalUserDelete').modal('toggle');
}

function prompModifyUser(id,name,username,email,dp_name) {
    g_modalValue = id;
    $("#tb_update_name").val(name);
    $("#tb_update_uname").val(username);
    $("#tb_update_mail").val(email);
    $("#modal_title_name").text(name);
    $("input[name=rb_update_dp]").val([dp_name]);

    $('#ModalModifyUser').modal('toggle');
}

function loadTableUserList() {
    try {
        dt_user_list.destroy();
    } catch (err) {}
    $('#table_user_list tbody > tr').remove();

    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "get_user_list"
        })
    }).done(function (data) {
        if(!data.error){  // no data
            $.each(data, function(key, value) {
                if(value.id == 1)
                    var action_items = `<button type="button" class="btn btn-info btn-sm" data-toggle="tooltip" data-placement="top" onclick="prompModifyUser('`+value.id+`','`+value.name+`','`+value.username+`','`+value.contact_mail+`','`+value.dp_name+`')" title="View/Edit"><i class="mdi mdi-pencil"></i></button>`;
                else
                    var action_items = `<button type="button" class="btn btn-info btn-sm" data-toggle="tooltip" data-placement="top" onclick="prompModifyUser('`+value.id+`','`+value.name+`','`+value.username+`','`+value.contact_mail+`','`+value.dp_name+`')" title="View/Edit"><i class="mdi mdi-pencil"></i></button><button type="button" class="btn btn-danger btn-sm" data-toggle="tooltip" data-placement="top" title="Delete" onclick="promptDeleteAccount('` + value.id + `')"><i class="mdi mdi-delete-variant"></i></button>`;

                $("#table_user_list tbody").append("<tr><td></td><td>" + value.name + "</td><td>" + value.username + "</td><td>" + value.contact_mail + "</td><td>" + roleSelectHtml(value.id, value.role) + "</td><td data-order=\"" + getTimestamp(value.date) + "\">" + value.date + "</td><td data-order=\"" + getTimestamp(value.last_login) + "\">" + value.last_login + "</td><td>" + action_items + "</td></tr>");
            });
        }
        
        dt_user_list = $('#table_user_list').DataTable({
            "bDestroy": true,
            'pageLength': 20,
            'lengthMenu': [[20, 50, 100, -1], [20, 50, 100, 'All']],
            "preDrawCallback": function(settings) {
                $('#table_user_list tbody').hide();
            },

            "drawCallback": function() {
                $('#table_user_list tbody').fadeIn(500);
                $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });
                $("label>select").select2({minimumResultsForSearch: -1, });
            }
        });

        dt_user_list.on('order.dt_user_list search.dt_user_list', function() {
            dt_user_list.column(0, {
                search: 'applied',
                order: 'applied'
            }).nodes().each(function(cell, i) {
                cell.innerHTML = i + 1;
            });
        }).draw();
    });   
}

// Phase 3.48 (RBAC): per-row global-role selector. The bootstrap admin (id 1)
// is pinned to super-admin server-side, so we disable its control to match.
function roleSelectHtml(id, role){
    var roles = [
        ['super-admin', 'Super Admin'],
        ['operator',    'Operator'],
        ['read-only',   'Read-only'],
        ['disabled',    'Disabled'],
    ];
    if(!role) role = 'operator';
    var locked = (id == 1) ? ' disabled title="The bootstrap admin must remain super-admin"' : '';
    var opts = '';
    for(var i = 0; i < roles.length; i++){
        var sel = (roles[i][0] === role) ? ' selected' : '';
        opts += '<option value="' + roles[i][0] + '"' + sel + '>' + roles[i][1] + '</option>';
    }
    return '<select class="form-control form-control-sm role-select" data-id="' + id + '"' + locked + ' onchange="setUserRoleAction(this)">' + opts + '</select>';
}

function setUserRoleAction(el){
    var id   = $(el).data('id');
    var role = $(el).val();
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: "set_user_role", id: id, role: role })
    }).done(function (response) {
        if(response.result == "success")
            toastr.success('', 'Role updated.');
        else {
            toastr.error('', response.error || 'Role update failed.');
            loadTableUserList(); // snap the control back to the server's truth
        }
    }).fail(function () {
        toastr.error('', 'Role update request failed.');
        loadTableUserList();
    });
}

function isPwdSecure(new_pwd, confirm_pwd, new_pwd_field, confirm_pwd_field){
    var f_valid = true;

    if(new_pwd != new_pwd.trim() || confirm_pwd != confirm_pwd.trim()){
        toastr.error('', 'Blank spaces at start/end is not permitted!');
        f_valid = false;
    }
    else{
        new_pwd = new_pwd.trim();
        confirm_pwd = confirm_pwd.trim();
        if(new_pwd != confirm_pwd){
            toastr.error('', 'Confirm password does not match!');
            f_valid = false;
        }
        else
        if(new_pwd.length<8){
            toastr.error('', 'Password length should be atleast 8 characters!');
            f_valid = false;
        }
    }

    if(f_valid){
        $(new_pwd_field).removeClass("is-invalid");
        $(confirm_pwd_field).removeClass("is-invalid");
    }
    else{
        $(new_pwd_field).addClass("is-invalid");
        $(confirm_pwd_field).addClass("is-invalid");
    }
    return f_valid;
}
// ---- Phase 3.25: TOTP 2FA management ---------------------------------

function totpPost(payload) {
    return $.ajax({
        url: 'manager/settings_manager',
        method: 'POST',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify(payload),
        dataType: 'json'
    });
}

function totpRefreshStatus() {
    totpPost({ action_type: 'totp_get_status' }).done(function (data) {
        if (!data || data.result !== 'success') return;
        if (data.enabled) {
            $('#totp_status_badge').removeClass().addClass('badge badge-success').text('Enabled');
            $('#btn_totp_enable').hide();
            $('#btn_totp_disable').show();
            // Phase 3.31: surface the regen button and recovery code count
            $('#btn_totp_regenerate').show();
            totpRefreshRecoveryStatus();
        } else {
            $('#totp_status_badge').removeClass().addClass('badge badge-secondary').text('Disabled');
            $('#btn_totp_enable').show();
            $('#btn_totp_disable').hide();
            $('#btn_totp_regenerate').hide();
            $('#totp_recovery_summary').hide();
        }
    });
}

// Phase 3.31: light-weight count refresh — never sends or reveals codes.
function totpRefreshRecoveryStatus() {
    totpPost({ action_type: 'totp_recovery_status' }).done(function (data) {
        if (!data || data.result !== 'success') return;
        var n = data.remaining | 0;
        var $s = $('#totp_recovery_summary');
        if (n === 0) {
            $s.html('<i class="fas fa-exclamation-triangle text-warning"></i> No recovery codes left — <a href="#" onclick="$(\'#modal_totp_regenerate\').modal(\'show\');return false;">regenerate</a> a fresh set.').show();
        } else if (n <= 3) {
            $s.html('<i class="fas fa-exclamation-circle text-warning"></i> Only ' + n + ' recovery code(s) left. Consider regenerating.').show();
        } else {
            $s.text(n + ' recovery codes remaining.').show();
        }
    });
}

$(function () {
    totpRefreshStatus();
});

function totpStartEnrollment() {
    $('#totp_enroll_err').text('');
    $('#totp_enroll_code').val('');
    totpPost({ action_type: 'totp_begin_enrollment' }).done(function (data) {
        if (!data || data.result !== 'success') {
            toastr.error('', (data && data.error) || 'Could not start 2FA enrollment.');
            return;
        }
        $('#totp_enroll_secret').val(data.secret);
        $('#totp_enroll_secret_text').text(data.secret);
        $('#totp_enroll_qr').attr('src', 'data:image/png;base64,' + data.qr_b64);
        $('#modal_totp_enroll').modal('show');
    });
}

function totpConfirmEnrollment(btn) {
    var secret = $('#totp_enroll_secret').val();
    var code   = $('#totp_enroll_code').val();
    if (!secret || !code) { $('#totp_enroll_err').text('Enter the 6-digit code.'); return; }
    enableDisableMe(btn);
    totpPost({ action_type: 'totp_confirm_enrollment', secret: secret, code: code })
        .done(function (data) {
            if (data && data.result === 'success') {
                $('#modal_totp_enroll').modal('hide');
                toastr.success('', '2FA is now enabled on your account.');
                totpRefreshStatus();
                // Phase 3.31: server returns the freshly-generated recovery
                // codes on the same response. Show them once — the only
                // chance the operator gets to copy them.
                if (data.recovery_codes && data.recovery_codes.length) {
                    totpShowRecoveryCodes(data.recovery_codes, data.recovery_warning);
                } else if (data.recovery_warning) {
                    toastr.warning('', data.recovery_warning);
                }
            } else {
                $('#totp_enroll_err').text((data && data.error) || 'Verification failed.');
            }
        })
        .fail(function (xhr) {
            $('#totp_enroll_err').text('Request failed (HTTP ' + xhr.status + ').');
        })
        .always(function () { enableDisableMe(btn); });
}

// Phase 3.31: shared display logic for the recovery-code list. Called
// from both the enrollment-success path and the regenerate-success path.
function totpShowRecoveryCodes(codes, warning) {
    $('#totp_recovery_codes_list').text(codes.join('\n'));
    if (warning) {
        $('#totp_recovery_warning').text(warning).show();
    } else {
        $('#totp_recovery_warning').hide();
    }
    $('#totp_recovery_ack').prop('checked', false);
    $('#totp_recovery_close').prop('disabled', true);
    $('#modal_totp_recovery_codes').modal('show');
}

function totpCopyRecoveryCodes() {
    var text = $('#totp_recovery_codes_list').text();
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(
            function () { toastr.success('', 'Copied.'); },
            function () { toastr.error('', 'Copy failed — select manually.'); }
        );
    } else {
        toastr.warning('', 'Clipboard API unavailable — select the codes manually.');
    }
}

function totpDownloadRecoveryCodes() {
    var text = $('#totp_recovery_codes_list').text();
    if (!text) return;
    var blob = new Blob([
        'TAPhish 2FA recovery codes\n',
        'Each code works exactly once.\n\n',
        text,
        '\n'
    ], { type: 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'taphish-2fa-recovery-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 200);
}

function totpDoRegenerate(btn) {
    var code = $('#totp_regenerate_code').val();
    if (!code) { $('#totp_regenerate_err').text('Enter your current 2FA code.'); return; }
    enableDisableMe(btn);
    totpPost({ action_type: 'totp_regenerate_recovery_codes', code: code })
        .done(function (data) {
            if (data && data.result === 'success') {
                $('#modal_totp_regenerate').modal('hide');
                $('#totp_regenerate_code').val('');
                $('#totp_regenerate_err').text('');
                toastr.success('', 'Fresh recovery codes generated.');
                totpRefreshRecoveryStatus();
                totpShowRecoveryCodes(data.recovery_codes || [], null);
            } else {
                $('#totp_regenerate_err').text((data && data.error) || 'Regeneration failed.');
            }
        })
        .fail(function (xhr) {
            $('#totp_regenerate_err').text('Request failed (HTTP ' + xhr.status + ').');
        })
        .always(function () { enableDisableMe(btn); });
}

function totpDoDisable(btn) {
    var pwd  = $('#totp_disable_pwd').val();
    var code = $('#totp_disable_code').val();
    if (!pwd || !code) { $('#totp_disable_err').text('Both fields are required.'); return; }
    enableDisableMe(btn);
    totpPost({ action_type: 'totp_disable', current_pwd: pwd, code: code })
        .done(function (data) {
            if (data && data.result === 'success') {
                $('#modal_totp_disable').modal('hide');
                $('#totp_disable_pwd').val('');
                $('#totp_disable_code').val('');
                toastr.success('', '2FA has been disabled.');
                totpRefreshStatus();
            } else {
                $('#totp_disable_err').text((data && data.error) || 'Disable failed.');
            }
        })
        .fail(function (xhr) {
            $('#totp_disable_err').text('Request failed (HTTP ' + xhr.status + ').');
        })
        .always(function () { enableDisableMe(btn); });
}
