// Phase 3.55 — look-alike domain deployment front-end. Builds the DNS helper,
// downloads the operator-hosted bundle, or publishes the TAPhish-hosted page.
var MGR = "manager/userlist_campaignlist_mailtemplate_manager";

$(function () {
    loadEngagements();
    loadClones();
    ldModeChanged();
});

function ldMode() {
    return $('input[name="ld_mode"]:checked').val() || 'operator';
}

function ldModeChanged() {
    var hosted = ldMode() === 'hosted';
    $('#ld_arec_row').toggle(!hosted);
    $('#ld_cname_row').toggle(hosted);
    if (hosted) {
        $('#ld_action_btn').html('<i class="fas fa-rocket"></i> Publish hosted page');
    } else {
        $('#ld_action_btn').html('<i class="fas fa-download"></i> Download bundle');
    }
}

function loadEngagements() {
    $.post({
        url: MGR, contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: "list_engagements" })
    }).done(function (r) {
        if (r && r.result === "success" && r.engagements) {
            $.each(r.engagements, function (i, e) {
                $('#ld_engagement').append($('<option>').val(e.id).text(e.name || ('Engagement #' + e.id)));
            });
        }
    });
}

function loadClones() {
    $.post({
        url: MGR, contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ action_type: "lookalike_list_clones" })
    }).done(function (r) {
        if (r && r.result === "success" && r.clones) {
            $.each(r.clones, function (i, slug) {
                $('#ld_slug').append($('<option>').val(slug).text(slug));
            });
        }
    });
}

function ldGenerate(btn) {
    var domain = $('#ld_domain').val().trim();
    if (domain === '') { toastr.error('', 'Enter the look-alike domain.'); return; }
    enableDisableMe(btn);
    $.post({
        url: MGR, contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({
            action_type: "lookalike_dns_records",
            domain: domain,
            mode: ldMode(),
            subdomain: $('#ld_subdomain').val().trim(),
            a_record: $('#ld_a_record').val().trim(),
            cname_target: $('#ld_cname_target').val().trim(),
            selector: $('#ld_selector').val().trim() || 's1',
            dkim_pubkey: $('#ld_dkim_pubkey').val().trim(),
            dmarc_rua: $('#ld_dmarc_rua').val().trim()
        })
    }).done(function (r) {
        if (r && r.result === "success") {
            renderRecords(r.records || []);
        } else {
            toastr.error('', (r && r.error) || 'Could not build records.');
        }
        enableDisableMe(btn);
    }).fail(function () { toastr.error('', 'Request failed.'); enableDisableMe(btn); });
}

function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

function renderRecords(records) {
    var $tb = $('#ld_dns_table tbody').empty();
    $.each(records, function (i, rec) {
        var copyBtn = '<button type="button" class="btn btn-outline-info btn-sm" onclick="ldCopy(this)" data-val="' + esc(rec.value) + '"><i class="fas fa-copy"></i></button>';
        $tb.append('<tr><td><span class="badge badge-info">' + esc(rec.type) + '</span></td>' +
            '<td style="font-family:monospace;">' + esc(rec.host) + '</td>' +
            '<td style="font-family:monospace;word-break:break-all;">' + esc(rec.value) + '</td>' +
            '<td>' + copyBtn + '</td>' +
            '<td class="small text-muted">' + esc(rec.note) + '</td></tr>');
    });
    $('#ld_result_card').show();
}

function ldCopy(el) {
    var v = $(el).data('val');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(v).then(
            function () { toastr.success('', 'Copied.'); },
            function () { toastr.error('', 'Copy failed.'); }
        );
    } else {
        toastr.warning('', 'Clipboard unavailable — select manually.');
    }
}

function ldPrimaryAction() {
    var slug = $('#ld_slug').val();
    if (!slug) { toastr.error('', 'Select a cloned landing page first.'); return; }
    if (ldMode() === 'hosted') {
        publishHosted(slug);
    } else {
        downloadBundle(slug);
    }
}

function downloadBundle(slug) {
    var params = $.param({
        slug: slug,
        engagement_id: $('#ld_engagement').val() || '',
        tracker_url: ''
    });
    window.location = 'LookalikeBundleExport.php?' + params;
}

function publishHosted(slug) {
    $.post({
        url: MGR, contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({
            action_type: "lookalike_publish_hosted",
            slug: slug,
            domain: $('#ld_domain').val().trim(),
            engagement_id: $('#ld_engagement').val() || ''
        })
    }).done(function (r) {
        if (r && r.result === "success") {
            $('#ld_published').html('Published: <a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.url) + '</a>').show();
            $('#ld_result_card').show();
            toastr.success('', 'Published.');
        } else {
            toastr.error('', (r && r.error) || 'Publish failed.');
        }
    }).fail(function () { toastr.error('', 'Publish request failed.'); });
}
