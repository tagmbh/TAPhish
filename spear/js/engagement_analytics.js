// Engagement Analytics — one consolidated funnel across an engagement's mail
// campaigns + web trackers (delivered → opened → clicked → credentials → OTP),
// by-send and by-cohort rollups, repeat offenders, and a timeline. Operator-tier
// (recipient emails are shown, per the decided privacy model). Reuses the tested
// engagement_analytics.php core via the engagement_analytics_summary action.
var g_engagement_id = '';

function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
function pct(x) { return (x == null ? 0 : x) + '%'; }

$(function () {
    $('#analytics_engagement_selector').select2({ placeholder: 'Select engagement' });
    loadEngagements();
    $('#analytics_engagement_selector').on('change', function () {
        g_engagement_id = this.value;
        if (g_engagement_id) { loadAnalytics(g_engagement_id); }
    });
});

function loadEngagements() {
    $.post({
        url: 'manager/userlist_campaignlist_mailtemplate_manager',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: 'list_engagements' })
    }).done(function (data) {
        var engs = (data && data.engagements) || [];
        var $sel = $('#analytics_engagement_selector').empty().append('<option></option>');
        engs.forEach(function (e) {
            $sel.append('<option value="' + e.id + '">' + esc(e.engagement_name || e.name || ('#' + e.id)) + '</option>');
        });
        // auto-select if exactly one engagement
        if (engs.length === 1) { $sel.val(String(engs[0].id)).trigger('change'); }
    });
}

function loadAnalytics(id) {
    $('#analytics_body').html('<div class="text-center m-t-30"><i class="fas fa-spinner fa-spin"></i> Loading…</div>');
    $.post({
        url: 'manager/userlist_campaignlist_mailtemplate_manager',
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: 'engagement_analytics_summary', engagement_id: id })
    }).done(function (d) {
        if (!d || d.result !== 'success') { $('#analytics_body').html('<div class="alert alert-warning">Could not load analytics.</div>'); return; }
        renderAnalytics(d);
    }).fail(function () {
        $('#analytics_body').html('<div class="alert alert-danger">Request failed.</div>');
    });
}

function kpiCard(label, count, rate) {
    return '<div class="col"><div class="card text-center"><div class="card-body p-3">'
        + '<h3 class="m-b-0">' + count + '</h3><span class="text-muted">' + label + '</span>'
        + (rate !== undefined ? '<div><span class="badge badge-info">' + pct(rate) + '</span></div>' : '')
        + '</div></div></div>';
}

function funnelRow(label, f) {
    return '<tr><td>' + esc(label) + '</td><td>' + f.delivered + '</td>'
        + '<td>' + f.opened + ' <small class="text-muted">(' + pct(f.rates.opened) + ')</small></td>'
        + '<td>' + f.clicked + ' <small class="text-muted">(' + pct(f.rates.clicked) + ')</small></td>'
        + '<td>' + f.credentials + ' <small class="text-muted">(' + pct(f.rates.credentials) + ')</small></td>'
        + '<td>' + f.otp + ' <small class="text-muted">(' + pct(f.rates.otp) + ')</small></td></tr>';
}

function tableBlock(title, keyLabel, grp) {
    var keys = Object.keys(grp || {});
    var html = '<div class="col-md-6"><div class="card"><div class="card-body"><h5 class="card-title">' + esc(title) + '</h5>';
    if (keys.length === 0) { return html + '<div class="text-muted">No data.</div></div></div></div>'; }
    html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>' + esc(keyLabel)
        + '</th><th>Deliv.</th><th>Open</th><th>Click</th><th>Cred.</th><th>OTP</th></tr></thead><tbody>';
    keys.forEach(function (k) { html += funnelRow(k, grp[k].funnel); });
    return html + '</tbody></table></div></div></div></div>';
}

function renderAnalytics(d) {
    var f = d.funnel;
    var html = '';

    html += '<div class="card-deck m-b-20">'
        + kpiCard('Delivered', f.delivered)
        + kpiCard('Opened', f.opened, f.rates.opened)
        + kpiCard('Clicked', f.clicked, f.rates.clicked)
        + kpiCard('Credentials', f.credentials, f.rates.credentials)
        + kpiCard('OTP', f.otp, f.rates.otp)
        + '</div>';
    html += '<div class="text-muted m-b-20">' + d.campaign_count + ' campaigns · ' + d.tracker_count + ' web trackers · ' + f.delivered + ' delivered recipients</div>';

    html += '<div class="row">' + tableBlock('By Send / Wave', 'Wave', d.by_wave) + tableBlock('By Cohort (User Group)', 'Cohort', d.by_cohort) + '</div>';

    // Repeat offenders
    html += '<div class="card"><div class="card-body"><h5 class="card-title">Repeat Offenders <small class="text-muted">(clicked in ≥2 sends)</small></h5>';
    var ro = d.repeat_offenders || [];
    if (ro.length === 0) { html += '<div class="text-muted">None yet.</div>'; }
    else {
        html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Name</th><th>Email</th><th>Waves</th><th>Clicks</th><th>Credentials</th></tr></thead><tbody>';
        ro.forEach(function (r) {
            html += '<tr><td>' + esc(r.name) + '</td><td>' + esc(r.email) + '</td><td>' + esc((r.waves || []).join(', ')) + '</td><td>' + r.clicks + '</td><td>' + (r.credentials || 0) + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }
    html += '</div></div>';

    // Timeline (most recent 25 events)
    var tl = (d.timeline || []).slice(-25).reverse();
    html += '<div class="card"><div class="card-body"><h5 class="card-title">Recent Activity <small class="text-muted">(latest ' + tl.length + ' of ' + (d.timeline || []).length + ')</small></h5>';
    if (tl.length === 0) { html += '<div class="text-muted">No click/credential/OTP activity yet.</div>'; }
    else {
        var badge = { click: 'badge-primary', credentials: 'badge-warning', otp: 'badge-danger' };
        html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Event</th><th>Wave</th><th>Cohort</th><th>Email</th></tr></thead><tbody>';
        tl.forEach(function (e) {
            html += '<tr><td><span class="badge ' + (badge[e.kind] || 'badge-secondary') + '">' + esc(e.kind) + '</span></td><td>' + esc(e.wave) + '</td><td>' + esc(e.cohort) + '</td><td>' + esc(e.email) + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }
    html += '</div></div>';

    $('#analytics_body').html(html);
}
