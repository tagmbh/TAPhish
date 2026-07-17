// tracker_reports_unified.js — Phase 2: ONE type-aware Tracker Reports page.
//
// Picks any tracker (web OR quick) from the shared list_all_trackers feed, then
// branches on the tracker's type:
//   quick → fixed columns, no page selector; feed quick_tracker_manager
//   web   → per-page (#reportTypeSelector) + dynamic Field-<name> columns; feed
//           tracker_report_manager
// Shared: the P2.2 scanner-hide toggle, one column picker, one export. The JS
// REPORT_CONFIG mirrors spear/manager/report_config.php (kept in lockstep).

var g_tracker_id = "", g_tracker_type = "";
var tdt;                       // the results DataTable
var dt_tracker_list;           // the picker DataTable
var allReportColList = [], allReportColListSelected = [];
var dic_all_col = {};          // set to the selected type's dict
var report_cols_html = [];     // web: per-page column-picker html

// Per-type report contract — mirror of taphish_report_config(). colsHtml is the
// default column-picker markup (from the two legacy report shells).
var REPORT_CONFIG = {
    quick: {
        manager: 'manager/quick_tracker_manager',
        action: 'get_quick_tracker_data',
        infoAction: 'get_quick_tracker_from_id',
        hasPageSelector: false,
        dict: { rid: 'RID', public_ip: 'Public IP', mail_client: 'Mail Client/Browser', platform: 'Platform', device_type: 'Device Type', all_headers: 'HTTP Headers', user_agent: 'User Agent', time: 'Hit Time', country: 'Country', city: 'City', zip: 'Zip', isp: 'ISP', timezone: 'Timezone', coordinates: 'Coordinates' },
        colsHtml: `<optgroup label="User Info">
                        <option value="rid" selected>RID</option>
                        <option value="public_ip" selected>Public IP</option>
                        <option value="mail_client" selected>Mail Client/Browser</option>
                        <option value="platform" selected>Platform</option>
                        <option value="all_headers">Req Headers</option>
                        <option value="time" selected>Hit Time</option>
                        <option value="user_agent">User Agent</option>
                   </optgroup>
                   <optgroup label="User/Mail Server IP Info">
                        <option value="country" selected>Country</option>
                        <option value="city">City</option>
                        <option value="zip">Zip</option>
                        <option value="isp">ISP</option>
                        <option value="timezone">Timezone</option>
                        <option value="coordinates">Coordinates</option>
                   </optgroup>`
    },
    web: {
        manager: 'manager/tracker_report_manager',
        action: 'get_table_webpage_visit_form_submission',
        infoAction: 'get_web_tracker_from_id',
        hasPageSelector: true,
        dict: { rid: 'RID', session_id: 'Session ID', public_ip: 'Public IP', user_agent: 'User Agent', time: 'Hit Time', browser: 'Browser', platform: 'Platform', screen_res: 'Screen Res', device_type: 'Device Type', country: 'Country', city: 'City', zip: 'Zip', isp: 'ISP', timezone: 'Timezone', coordinates: 'Coordinates' },
        colsHtml: `<optgroup label="User Info">
                        <option value="rid" selected>Client ID</option>
                        <option value="session_id">Session ID</option>
                        <option value="public_ip" selected>Public IP</option>
                        <option value="time" selected>Hit Time</option>
                        <option value="browser" selected>Browser</option>
                        <option value="platform" selected>Platform</option>
                        <option value="screen_res" selected>Screen Res</option>
                        <option value="device_type">Device Type</option>
                        <option value="user_agent">User Agent</option>
                   </optgroup>
                   <optgroup label="User IP Info">
                        <option value="country" selected>Country</option>
                        <option value="city">City</option>
                        <option value="zip">Zip</option>
                        <option value="isp">ISP</option>
                        <option value="timezone">Timezone</option>
                        <option value="coordinates">Coordinates</option>
                   </optgroup>`
    }
};

var TYPE_BADGE = { web: 'badge-info', quick: 'badge-warning' };

$("#reportTypeSelector").select2({ minimumResultsForSearch: -1 });
$("#modal_export_report_selector").select2({ minimumResultsForSearch: -1 });
$('#tb_report_colums_list').select2().on("select2:select", function (evt) {
    var $element = $(evt.params.data.element);
    $element.detach();
    $(this).find('optgroup').append($element);
    $(this).trigger("change");
});
$("#tb_report_colums_list").parent().find("ul.select2-selection__rendered").sortable({
    containment: 'parent',
    update: function () { getAllReportColListSelected(); }
});

// P2.2 scanner-hide toggle → reload (delegated; tdt exists only after a pick).
$(function () {
    $(document).on('change', '#cb_hide_scanner', function () {
        try { if (tdt) { tdt.ajax.reload(); } } catch (e) {}
    });
});

function getAllReportColListSelected() {
    allReportColList = [];
    allReportColListSelected = [];
    $.each($("#tb_report_colums_list").find("option"), function () {
        allReportColList[$(this).text()] = $(this).val();
    });
    $.each($("#tb_report_colums_list").parent().find("ul.select2-selection__rendered").children("li[title]"), function () {
        allReportColListSelected.push(allReportColList[this.title]);
    });
}

// web page selector → rebuild the column picker for that page + reload.
$('#reportTypeSelector').on('change', function () {
    if (g_tracker_type !== 'web') return;
    $('#tb_report_colums_list').empty();
    $('#tb_report_colums_list').append(report_cols_html[$(this)[0].selectedIndex]);
    $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });
    $('#tb_report_colums_list').trigger("change");
    loadResults();
});

// ---- picker: list every tracker (web + quick), type-tagged ----
$(document).ready(function () {
    $.post({
        url: 'manager/web_tracker_generator_list_manager',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: 'list_all_trackers' })
    }).done(function (data) {
        var trackers = (data && data.trackers) || [];
        var $body = $("#Modal_table_tracker_list tbody");
        trackers.forEach(function (t) {
            var badge = TYPE_BADGE[t.type] || 'badge-secondary';
            $body.append('<tr><td></td>'
                + '<td><span class="badge ' + badge + '">' + t.type + '</span></td>'
                + '<td>' + t.tracker_id + '</td>'
                + '<td>' + t.tracker_name + '</td>'
                + '<td data-order="' + getTimestamp(t.date) + '">' + t.date + '</td>'
                + '<td><button type="button" class="btn btn-info btn-sm" data-toggle="tooltip" title="Select" data-dismiss="modal" onClick="trackerSelected(\'' + t.type + '\',\'' + t.tracker_id + '\');window.history.replaceState(null,null, location.pathname + \'?type=' + t.type + '&tracker=' + t.tracker_id + '\');">Select</button></td></tr>');
        });

        dt_tracker_list = $('#Modal_table_tracker_list').DataTable({
            "bDestroy": true,
            "pageLength": 5,
            "lengthMenu": [5, 10, 20, 50, 100],
            "order": [[4, 'desc']],   // Date Created desc
            "preDrawCallback": function () { $('#Modal_table_tracker_list tbody').hide(); },
            "drawCallback": function () {
                $('#Modal_table_tracker_list tbody').fadeIn(500);
                $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });
            },
            "initComplete": function () { $('label>select').select2({ minimumResultsForSearch: -1 }); }
        });

        dt_tracker_list.on('order.dt search.dt', function () {
            dt_tracker_list.column(0, { search: 'applied', order: 'applied' }).nodes().each(function (cell, i) {
                cell.innerHTML = i + 1;
            });
        }).draw();
    });
});

// ---- select a tracker of either type ----
function trackerSelected(type, tracker_id) {
    if (!tracker_id) { toastr.warning('', 'Tracker not selected'); return; }
    var cfg = REPORT_CONFIG[type];
    if (!cfg) { toastr.error('', 'Unknown tracker type'); return; }

    g_tracker_type = type;
    g_tracker_id = tracker_id;
    dic_all_col = cfg.dict;

    // the page selector only applies to web trackers
    cfg.hasPageSelector ? $('#reportTypeSelector').closest('.col-md-2').show() : $('#reportTypeSelector').closest('.col-md-2').hide();

    $.post({
        url: cfg.manager,
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: cfg.infoAction, tracker_id: tracker_id })
    }).done(function (data) {
        $('#disp_web_tracker_name').text(data.tracker_name);
        $('#Modal_export_file_name').val(data.tracker_name);
        $('#disp_tracker_start').text((data.start_time == '' || data.start_time == undefined) ? "Not started" : data.start_time);
        if (data['active'] == 0)
            $('#disp_tracker_status').html(`<span class="badge badge-pill badge-success" data-toggle="tooltip" title="Tracking status"><i class="mdi mdi-watch-vibrate"></i> Stopped</span>`);
        else
            $('#disp_tracker_status').html(`<span class="badge badge-pill badge-primary" data-toggle="tooltip" title="Tracking status"><i class="mdi mdi-watch-vibrate"></i> In-progress</span>`);
        $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" });

        report_cols_html = [];
        if (type === 'web') {
            $("#reportTypeSelector").empty();
            $("#reportTypeSelector").append('<option value=0>Page Visit</option>');
            report_cols_html[0] = cfg.colsHtml;
            var tracker_step_data = data.tracker_step_data;
            $.each(tracker_step_data.web_forms.data, function (i, wf_data) {
                $("#reportTypeSelector").append('<option value=' + (i + 1) + '>Page ' + (i + 1) + ' (' + wf_data.page_name + ')</option>');
                report_cols_html[i + 1] = cfg.colsHtml;
                if (Object.keys(wf_data.form_fields_and_values).length > 0) {
                    report_cols_html[i + 1] += '<optgroup label="Form Input Fields">';
                    $.each(wf_data.form_fields_and_values, function (field_type, form_field) {
                        if (field_type != "FSB")
                            report_cols_html[i + 1] += '<option value="Field-' + form_field.idname + '" selected>Field-' + form_field.idname + '</option>';
                    });
                    report_cols_html[i + 1] += '</optgroup>';
                }
            });
            $('#tb_report_colums_list').empty();
            $('#tb_report_colums_list').append(report_cols_html[0]);
            $('#tb_report_colums_list').trigger("change");
        } else {
            // quick: fixed columns, no pages
            $('#tb_report_colums_list').empty();
            $('#tb_report_colums_list').append(cfg.colsHtml);
            $('#tb_report_colums_list').trigger("change");
        }
        loadResults();
    });
}

// ---- one results table for either type ----
function loadResults() {
    var cfg = REPORT_CONFIG[g_tracker_type];
    if (!cfg) return;
    var web_page = cfg.hasPageSelector ? $('#reportTypeSelector')[0].selectedIndex : 0;

    try { tdt.destroy(); } catch (err) {}
    $('#table_tracker_report thead').empty();
    $('#table_tracker_report tbody > tr').remove();

    getAllReportColListSelected();
    var arr_tb_heading = [];
    arr_tb_heading.push({ data: 'sn', title: "SN" });
    $.each(allReportColListSelected, function (index, item) {
        if (item && item.startsWith("Field-"))
            arr_tb_heading.push({ data: item, title: item });
        else
            arr_tb_heading.push({ data: item, title: dic_all_col[item] });
    });

    tdt = $('#table_tracker_report').DataTable({
        'processing': true,
        'serverSide': true,
        'ajax': {
            url: cfg.manager,
            type: "POST",
            contentType: "application/json; charset=utf-8",
            data: function (d) {
                d.action_type = cfg.action;
                d.tracker_id = g_tracker_id;
                d.selected_col = allReportColListSelected;
                d.hide_scanner = $('#cb_hide_scanner').is(':checked');
                if (cfg.hasPageSelector) d.page = web_page;
                return JSON.stringify(d);
            },
            dataSrc: function (resp) {
                for (var i = 0; i < resp.data.length; i++)
                    resp.data[i]['sn'] = i + 1;
                return resp.data;
            }
        },
        'columns': arr_tb_heading,
        'pageLength': 20,
        'lengthMenu': [[20, 50, 100, 500, 1000, -1], [20, 50, 100, 500, 1000, "All"]],
        'aoColumnDefs': [{ 'bSortable': false, 'aTargets': [0] }],
        drawCallback: function () { $('[data-toggle="tooltip"]').tooltip({ trigger: "hover" }); },
        "initComplete": function () { $('label>select').select2({ minimumResultsForSearch: -1 }); }
    });
}

function exportReport() {
    $('#Modal_export_file_name').val(g_tracker_id + '_' + $('#disp_web_tracker_name').text());
    $('#ModalExport').modal('toggle');
}

function exportReportAction(e) {
    if (!tdt || tdt.rows().count() == 0) { toastr.error('', 'Table is empty!'); return; }
    var cfg = REPORT_CONFIG[g_tracker_type];
    var file_name = $('#Modal_export_file_name').val().trim();
    var file_format = $('#modal_export_report_selector').val();
    var content_type = file_format == 'pdf' ? 'application/pdf' : (file_format == 'html' ? 'text/html' : 'text/csv');
    getAllReportColListSelected();

    var body = {
        action_type: "download_report",
        tracker_id: g_tracker_id,
        selected_col: allReportColListSelected,
        dic_all_col: dic_all_col,
        file_name: file_name,
        file_format: file_format
    };
    if (cfg.hasPageSelector) body.page = $('#reportTypeSelector')[0].selectedIndex;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', cfg.manager, true);
    xhr.responseType = 'arraybuffer';
    if (window.TAPHISH_CSRF) xhr.setRequestHeader('X-CSRF-Token', window.TAPHISH_CSRF);
    enableDisableMe(e);
    xhr.send(JSON.stringify(body));
    xhr.onload = function () {
        if (this.status == 200) {
            var link = document.createElement('a');
            link.href = window.URL.createObjectURL(new Blob([this.response], { type: content_type }));
            link.download = file_name + '.' + file_format;
            link.click();
            $('#ModalExport').modal('toggle');
        }
        enableDisableMe(e);
    };
}
