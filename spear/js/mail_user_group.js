var row_index;
var dt_user_group_list;
var action_items = '<button type="button" class="btn btn-danger btn-sm" data-toggle="tooltip" data-placement="top" title="" onclick="deleteRow($(this))" data-original-title="Delete"><i class="mdi mdi-delete-variant"></i></button> <button type="button" class="btn btn-info btn-sm" data-toggle="tooltip" data-placement="top" title="" onclick="editRow($(this))" data-original-title="Edit"><i class="mdi mdi-pencil"></i></button>';

dt_user_list = $('#table_user_list').DataTable({
    "preDrawCallback": function(settings) {
        $('#table_user_list tbody').hide();
    },

    "drawCallback": function() {
        $('#table_user_list tbody').fadeIn(500);
    },
});

$(function() {
    $("#modal_export_report_selector").select2({
        minimumResultsForSearch: -1,
    }); 
    $('#section_adduser').click(function(){
        g_deny_navigation = '';
    });
});  

function exportUserAction() {
    if(dt_user_list.rows().count() > 0){
        var file_name = $('#user_group_name').val().trim();
        
        $.post({
            url: "manager/userlist_campaignlist_mailtemplate_manager",
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify({ 
                action_type: "download_user",
                user_group_id: nextRandomId,
            })
        }).done(function (response) {
            if(response.error)
                toastr.error('', 'Error exporting user!');
            else{
                var a = window.document.createElement('a');
                a.href = window.URL.createObjectURL(new Blob([response],{ type: 'text/csv'}));
                a.download = file_name + '.csv';

                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        }); 
    }
}

function addUserToTable(e) {
    var user_group_name = $('#user_group_name').val().trim();
    var field_fname = $('#tablevalue_fname').val().trim();
    var field_lname = $('#tablevalue_lname').val().trim();
    var field_email = $('#tablevalue_email').val();
    var field_notes = $('#tablevalue_notes').val().trim()

    if (RegTest(user_group_name,'COMMON') == false) {
        $("#user_group_name").addClass("is-invalid");
        toastr.error('', 'Empty/Unsupported character!');
        return;
    } else
        $("#user_group_name").removeClass("is-invalid");

    if (field_fname == "")
        field_fname = "Empty";
    if (field_lname == "")
        field_lname = "Empty";

    if (RegTest(field_email, "EMAIL") == false) {
        $("#tablevalue_email").addClass("is-invalid");
        return;
    } else
        $("#tablevalue_email").removeClass("is-invalid");

    enableDisableMe(e);
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "add_user_to_table",
            user_group_id: nextRandomId,
            user_group_name: user_group_name,
            fname: field_fname,
            lname: field_lname,
            email: field_email,
            notes: field_notes
        })
    }).done(function (response) {
        if(response.error)
            toastr.error('', 'Error adding user!');
        else{
            getUserGroupFromGroupId(nextRandomId);
            window.history.replaceState(null,null, location.pathname + '?action=edit&user=' + nextRandomId);
        }
        enableDisableMe(e);
    }); 
}

function deleteRow(arg) {
    row_index = dt_user_list.row(arg.parents('tr')).index();    
    $('#modal_row_delete').modal('toggle');
}

function deleteRowAction(e) {
    var uid = dt_user_list.row(row_index).data().uid;

    enableDisableMe(e);
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "delete_user",
            user_group_id: nextRandomId,
            uid: uid,
        })
    }).done(function (response) {
        if(response.result == "success"){
            toastr.success('', 'Deleted successfully!');
            $('#modal_row_delete').modal('toggle');
            getUserGroupFromGroupId(nextRandomId);
        }
        else
            toastr.error('', response.error);
        enableDisableMe(e);
    }); 
}

function editRow(arg) {
    row_index = dt_user_list.row(arg.parents('tr')).index();

    $('#modal_tablevalue_fname').val(dt_user_list.row(row_index).data().fname);
    $('#modal_tablevalue_lname').val(dt_user_list.row(row_index).data().lname);
    $('#modal_tablevalue_email').val(dt_user_list.row(row_index).data().email);
    $('#modal_tablevalue_notes').val(dt_user_list.row(row_index).data().notes);
    $('#modal_modify_row').modal('toggle');
}

function editRowAction(e) {
    var field_fname = $('#modal_tablevalue_fname').val().trim();
    var field_lname = $('#modal_tablevalue_lname').val().trim();
    var field_email = $('#modal_tablevalue_email').val().trim();
    var field_notes = $('#modal_tablevalue_notes').val().trim();
    var uid = dt_user_list.row(row_index).data().uid;

    if (field_fname == "")
        field_fname = "Empty";
    if (field_lname == "")
        field_lname = "Empty";

    if (RegTest(field_email, "EMAIL") == false) {
        $("#modal_tablevalue_email").addClass("is-invalid");
        return;
    } else
        $("#modal_tablevalue_email").removeClass("is-invalid");

    enableDisableMe(e);
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "update_user",
            user_group_id: nextRandomId,
            uid: uid,
            fname: field_fname,
            lname: field_lname,
            email: field_email,
            notes: field_notes,
        })
    }).done(function (response) {
        if(response.result == "success"){
            toastr.success('', 'Updated successfully!');
            $('#modal_modify_row').modal('toggle');
            getUserGroupFromGroupId(nextRandomId);
            g_deny_navigation = null;
        }
        else
            toastr.error('', response.error);
        enableDisableMe(e);
    }); 
}

function addUserFromFile() {
    if (RegTest($('#user_group_name').val(),'COMMON') == false) {
        $("#user_group_name").addClass("is-invalid");
        toastr.error('', 'Empty/Unsupported character! Provide a valid name first.');
        return;
    } else{
        $("#user_group_name").removeClass("is-invalid");
        $('input[type=file]').trigger('click');
    }
}

$('input[type=file]').change(function() {
    if (RegTest($('#user_group_name').val(),'COMMON') == false) {
        $("#user_group_name").addClass("is-invalid");
        toastr.error('', 'Empty/Unsupported character!');
        return;
    } else
        $("#user_group_name").removeClass("is-invalid");

    var file = $('#fileinput').prop('files')[0];

    var fileExtension = ['csv', 'txt', 'lst', 'rtf'];
    if ($.inArray($(this).val().split('.').pop().toLowerCase(), fileExtension) == -1) {
        toastr.error('', 'Unsupported file type!');
        return;
    }

    if (file) {
        var reader = new FileReader();
        reader.readAsText(file, "UTF-8");
        reader.onload = function(evt) {
            var user_data = evt.target.result;

            $.post({
                url: "manager/userlist_campaignlist_mailtemplate_manager",
                contentType: 'application/json; charset=utf-8',
                data: JSON.stringify({ 
                    action_type: "upload_user",
                    user_group_id: nextRandomId,
                    user_data : user_data,
                    user_group_name: $('#user_group_name').val().trim()
                })
            }).done(function (response) {
                if(response.result == "success"){
                    toastr.success('', 'User list added successfully!');
                    getUserGroupFromGroupId(nextRandomId);
                }
                else
                    toastr.error('', response.error);
            }); 
        }
        reader.onerror = function(evt) {
            toastr.error('', 'Error reading file!');
        }
    }
});

function saveUserGroup(e) {
    if (RegTest($('#user_group_name').val(),'COMMON') == false) {
        $("#user_group_name").addClass("is-invalid");
        toastr.error('', 'Empty/Unsupported character!');
        return;
    } else
        $("#user_group_name").removeClass("is-invalid");

    enableDisableMe(e);
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "save_user_group",
            user_group_id: nextRandomId,
            user_group_name: $('#user_group_name').val().trim()
        })
    }).done(function (response) {
        if(response.result == "success"){
            toastr.success('', 'Saved successfully!');
            window.history.replaceState(null,null, location.pathname + '?action=edit&user=' + nextRandomId);
            g_deny_navigation = null;
        }
        else
            toastr.error('', 'Error saving data!');
        enableDisableMe(e);
    }); 
}

function getUserGroupFromGroupId(id) {
    if (id == "new") {
        getRandomId();
        return;
    } else
        nextRandomId = id;

    try{
        dt_user_list.destroy();
    }catch{}

    dt_user_list = $('#table_user_list').DataTable({
        'processing': true,
        'serverSide': true,
        'ajax': {
           url:'manager/userlist_campaignlist_mailtemplate_manager',
           type: "POST",
           contentType: "application/json; charset=utf-8",
           data: function (d) {   //request parameters here
                    d.action_type="get_user_group_from_group_Id_table";
                    d.user_group_id=id;
                    return JSON.stringify(d);
                },
            dataSrc: function ( resp ){
                for ( var i=0, ien=resp.data.length ; i<ien ; i++ ) {
                    resp.data[i]['sn'] = i+1;
                    resp.data[i]['action'] = action_items;
                }
                $('#user_group_name').val(resp.user_group_name);
                $('#Modal_export_file_name').val(resp.user_group_name);
                return resp.data
            }
        },
        'columns': [
           { data: 'sn' }, 
           { data: 'fname' },
           { data: 'lname' },
           { data: 'email' },
           { data: 'notes' },
           { data: 'action' },
        ],
        'columnDefs': [{'targets':5, 'className':'dt-center'}],
        'pageLength': 20,
        'lengthMenu': [[20, 50, 100, 500, 1000, -1], [20, 50, 100, 500, 1000, 'All']],
        drawCallback:function(){
            $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });
        },
        "initComplete": function() {
            $('label>select').select2({minimumResultsForSearch: -1, });
        }
  });
}


//===============================================
function promptUserGroupDeletion(id) {
    globalModalValue = id;
    $('#modal_user_group_delete').modal('toggle');
}

function userGroupDeletionAction() {
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "delete_user_group_from_group_id",
            user_group_id: globalModalValue
        })
    }).done(function (response) {
        if(response.result == "success"){
           $('#modal_user_group_delete').modal('toggle');
            toastr.success('', 'Deleted successfully!');
            dt_user_group_list.destroy();
            $("#table_user_group_list tbody > tr").remove();
            loadTableUserGroupList();
        }
        else
            toastr.error("", response.error);
    }); 
}

function promptUserGroupCopy(id) {
    globalModalValue = id;
    $('#modal_user_group_copy').modal('toggle');
}

function UserGroupCopy() {
    if (RegTest($('#modal_new_user_group_name').val(), 'COMMON') == false) {
        $("#modal_new_user_group_name").addClass("is-invalid");
        toastr.error('', 'Empty/Unsupported character!');
        return;
    } else
        $("#modal_new_user_group_name").removeClass("is-invalid");

    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "make_copy_user_group",
            user_group_id: globalModalValue,
            new_user_group_id: getRandomId(),
            new_user_group_name: $("#modal_new_user_group_name").val()
         })
    }).done(function (response) {
        if(response.result == "success"){
            toastr.success('', 'Copy success!');
            $('#modal_user_group_copy').modal('toggle');
            dt_user_group_list.destroy();
            $("#table_user_group_list tbody > tr").remove();
            loadTableUserGroupList();
        }
        else
            toastr.error("", response.error);
    }); 
}

function loadTableUserGroupList() {
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "get_user_group_list"
         })
    }).done(function (data) {
        if(!data.error){  // no data
             $.each(data, function(key, value) {
                var action_items_user_group_table = `<button type="button" class="btn btn-info btn-sm" data-toggle="tooltip" data-placement="top" onclick="document.location='MailUserGroup?action=edit&user=` + value.user_group_id + `'" title="View/Edit"><i class="mdi mdi-pencil"></i></button><button type="button" class="btn btn-success btn-sm" data-toggle="tooltip" data-placement="top" title="Copy" onclick="promptUserGroupCopy('` + value.user_group_id + `')"><i class="mdi mdi-content-copy"></i></button><button type="button" class="btn btn-danger btn-sm" data-toggle="tooltip" data-placement="top" title="Delete" onclick="promptUserGroupDeletion('` + value.user_group_id + `')"><i class="mdi mdi-delete-variant"></i></button>`;
                $("#table_user_group_list tbody").append("<tr><td></td><td>" + value.user_group_name + "</td><td>" + value.user_count + "</td><td data-order=\"" + getTimestamp(value.date) + "\">" + value.date + "</td><td>" + action_items_user_group_table + "</td></tr>");
            });
        }
        dt_user_group_list = $('#table_user_group_list').DataTable({
            "bDestroy": true,
            "aaSorting": [3, 'asc'],
            'columnDefs': [{
                "targets": 4,
                "className": "dt-center"
            }],
            
            "preDrawCallback": function(settings) {
                $('#table_user_group_list tbody').hide();
            },

            "drawCallback": function() {
                $('#table_user_group_list tbody').fadeIn(500);
                $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });
            },   

            "initComplete": function() {
                $('label>select').select2({minimumResultsForSearch: -1, });
            }       
        });

        dt_user_group_list.on('order.dt_user_group_list search.dt_user_group_list', function() {
            dt_user_group_list.column(0, {
                search: 'applied',
                order: 'applied'
            }).nodes().each(function(cell, i) {
                cell.innerHTML = i + 1;
            });
        }).draw();        
    });   
}


// ---- OSINT — Hunter.io domain search ---------------------------------
const OSINT_HUNTER_KEY_LS = "taphish_hunter_apikey";

$(function () {
    var saved = localStorage.getItem(OSINT_HUNTER_KEY_LS);
    if (saved) $("#osint_hunter_apikey").val(saved);
});

function osintHunterSearch(e) {
    var apiKey = $("#osint_hunter_apikey").val().trim();
    var domain = $("#osint_hunter_domain").val().trim();
    if (!apiKey || !domain) {
        toastr.error("", "API key and domain are both required.");
        return;
    }
    localStorage.setItem(OSINT_HUNTER_KEY_LS, apiKey);
    enableDisableMe(e);
    $("#osint_hunter_summary").text("Searching…");
    $("#osint_hunter_results tbody").empty();
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: "application/json; charset=utf-8",
        data: JSON.stringify({
            action_type: "osint_hunter_search",
            domain: domain,
            api_key: apiKey,
            limit: 50
        })
    }).done(function (data) {
        if (data && data.result === "success") {
            renderOsintHunterResults(data);
        } else {
            $("#osint_hunter_summary").text("");
            toastr.error("", (data && data.err) || "OSINT search failed.");
        }
    }).fail(function (xhr) {
        $("#osint_hunter_summary").text("");
        toastr.error("", "Request failed (HTTP " + xhr.status + ").");
    }).always(function () {
        enableDisableMe(e);
    });
}

function renderOsintHunterResults(data) {
    var summary = (data.organization ? data.organization + " — " : "") + (data.domain || "");
    summary += " · " + (data.results ? data.results.length : 0) + " result(s)";
    $("#osint_hunter_summary").text(summary);
    var tbody = $("#osint_hunter_results tbody").empty();
    if (!data.results || data.results.length === 0) {
        tbody.append("<tr><td colspan=5 class=\"text-muted\">No emails returned.</td></tr>");
        return;
    }
    data.results.forEach(function (r) {
        var $tr = $("<tr>");
        var cb = $("<input type=\"checkbox\" class=\"osint-row-cb\">")
            .data("email", r.email).data("first", r.first_name).data("last", r.last_name);
        $tr.append($("<td>").append(cb));
        $tr.append($("<td>").append($("<code>").text(r.email)));
        $tr.append($("<td>").text(r.name || "—"));
        $tr.append($("<td>").text(r.position || "—"));
        $tr.append($("<td>").text(r.confidence + "%"));
        tbody.append($tr);
    });
}

function osintHunterToggleAll(el) {
    $(".osint-row-cb").prop("checked", el.checked);
}

function osintHunterImportSelected() {
    var user_group_name = $("#user_group_name").val().trim();
    if (RegTest(user_group_name, "COMMON") === false) {
        $("#user_group_name").addClass("is-invalid");
        toastr.error("", "Set a user-group name first.");
        return;
    }
    var checked = $(".osint-row-cb:checked");
    if (!checked.length) {
        toastr.warning("", "Nothing selected.");
        return;
    }
    var imported = 0;
    var pending = checked.length;
    checked.each(function () {
        var $cb = $(this);
        var first = $cb.data("first") || "Empty";
        var last  = $cb.data("last")  || "Empty";
        var email = $cb.data("email");
        $.post({
            url: "manager/userlist_campaignlist_mailtemplate_manager",
            contentType: "application/json; charset=utf-8",
            data: JSON.stringify({
                action_type: "add_user_to_table",
                user_group_id: nextRandomId,
                user_group_name: user_group_name,
                fname: first,
                lname: last,
                email: email,
                notes: "OSINT/Hunter.io"
            })
        }).always(function () {
            imported++;
            if (imported >= pending) {
                toastr.success("", "Imported " + imported + " recipient(s).");
                $("#modal_osint_hunter").modal("toggle");
                if (typeof loadTableUserList === "function") loadTableUserList();
                else location.reload();
            }
        });
    });
}


function osintHunterFind(e) {
    var apiKey = $("#osint_hunter_apikey").val().trim();
    var domain = $("#osint_hunter_domain").val().trim();
    var first  = $("#osint_hunter_first").val().trim();
    var last   = $("#osint_hunter_last").val().trim();
    if (!apiKey || !domain) {
        toastr.error("", "API key and domain are both required.");
        return;
    }
    if (!first && !last) {
        toastr.error("", "Enter at least a first or last name.");
        return;
    }
    localStorage.setItem(OSINT_HUNTER_KEY_LS, apiKey);
    enableDisableMe(e);
    $("#osint_hunter_summary").text("Looking up…");
    $("#osint_hunter_results tbody").empty();
    $.post({
        url: "manager/userlist_campaignlist_mailtemplate_manager",
        contentType: "application/json; charset=utf-8",
        data: JSON.stringify({
            action_type: "osint_hunter_email_finder",
            domain: domain,
            first_name: first,
            last_name: last,
            api_key: apiKey
        })
    }).done(function (data) {
        if (data && data.result === "success") {
            renderOsintHunterResults(data);
        } else {
            $("#osint_hunter_summary").text("");
            toastr.error("", (data && data.err) || "Email-finder failed.");
        }
    }).fail(function (xhr) {
        $("#osint_hunter_summary").text("");
        toastr.error("", "Request failed (HTTP " + xhr.status + ").");
    }).always(function () {
        enableDisableMe(e);
    });
}
