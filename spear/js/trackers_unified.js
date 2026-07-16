// P2.1 — unified tracker list (web + quick in one table, Type column + filter).
// Read-only aggregation; per-row Report/Edit deep-link to the existing pages
// (unified Reports/New come in P2.2/P2.3). Applies the P2.0 DataTables fixes:
// well-formed order + the real order.dt/search.dt event namespace.
var dt_all_trackers;
var g_engMap = {};

var TR_TYPE = {
    web:   { label: 'Web',   cls: 'badge-info',
             report: function (id) { return 'TrackerReport?tracker=' + encodeURIComponent(id); },
             edit:   function (id) { return 'TrackerGenerator?tracker=' + encodeURIComponent(id); } },
    quick: { label: 'Quick', cls: 'badge-warning',
             report: function (id) { return 'QuickTrackerReport?tracker=' + encodeURIComponent(id); },
             edit:   function ()   { return 'QuickTracker'; } }
};

function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

$(function () {
    $('#tracker_type_filter button').on('click', function () {
        $('#tracker_type_filter button').removeClass('active');
        $(this).addClass('active');
        var type = $(this).data('type') || '';
        var label = type === 'web' ? 'Web' : (type === 'quick' ? 'Quick' : '');
        if (dt_all_trackers) {
            // Type column (1) holds the badge text "Web"/"Quick" — no collision.
            dt_all_trackers.column(1).search(label).draw();
        }
    });
    loadAllTrackers();
});

function loadAllTrackers() {
    $.when(
        $.post({ url: 'manager/web_tracker_generator_list_manager', contentType: 'application/json; charset=utf-8', data: JSON.stringify({ action_type: 'list_all_trackers' }) }),
        $.post({ url: 'manager/userlist_campaignlist_mailtemplate_manager', contentType: 'application/json; charset=utf-8', data: JSON.stringify({ action_type: 'list_engagements' }) })
    ).done(function (tRes, eRes) {
        var trackers = (tRes && tRes[0] && tRes[0].trackers) || [];
        var engs = (eRes && eRes[0] && eRes[0].engagements) || [];
        g_engMap = {};
        engs.forEach(function (e) { g_engMap[String(e.id)] = e.name || e.slug; });
        renderAllTrackers(trackers);
    }).fail(function () {
        if (window.toastr) { toastr.error('', 'Could not load trackers'); }
    });
}

function renderAllTrackers(trackers) {
    if (dt_all_trackers) { dt_all_trackers.destroy(); }
    var $body = $('#table_all_trackers tbody').empty();
    trackers.forEach(function (t) {
        var meta = TR_TYPE[t.type] || { label: t.type || '?', cls: 'badge-secondary', report: function () { return '#'; }, edit: function () { return '#'; } };
        var eng = t.engagement_id != null ? (g_engMap[String(t.engagement_id)] || ('#' + t.engagement_id)) : '— Unscoped —';
        var status = t.active
            ? '<span class="badge badge-success">Active</span>'
            : '<span class="badge badge-secondary">Inactive</span>';
        var actions = '<a class="btn btn-sm btn-outline-info" href="' + meta.report(t.tracker_id) + '">Report</a> '
            + '<a class="btn btn-sm btn-outline-secondary" href="' + meta.edit(t.tracker_id) + '">Edit</a>';
        $body.append('<tr>'
            + '<td></td>'
            + '<td><span class="badge ' + meta.cls + '">' + esc(meta.label) + '</span></td>'
            + '<td>' + esc(t.tracker_id) + '</td>'
            + '<td>' + esc(t.tracker_name) + '</td>'
            + '<td>' + status + '</td>'
            + '<td class="small">' + esc(eng) + '</td>'
            + '<td data-order="' + getTimestamp(t.date) + '">' + esc(t.date) + '</td>'
            + '<td>' + actions + '</td>'
            + '</tr>');
    });

    dt_all_trackers = $('#table_all_trackers').DataTable({
        "bDestroy": true,
        "order": [[6, 'desc']],   // Date Created, newest first
        'pageLength': 20,
        'lengthMenu': [[20, 50, 100, -1], [20, 50, 100, 'All']],
        'columnDefs': [{ "targets": [7], "orderable": false }]
    });

    dt_all_trackers.on('order.dt search.dt', function () {
        dt_all_trackers.column(0, { search: 'applied', order: 'applied' }).nodes().each(function (cell, i) {
            cell.innerHTML = i + 1;
        });
    }).draw();
}
