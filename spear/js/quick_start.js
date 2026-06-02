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

    // ----- Step 2: OSINT pre-check fan-out --------------------------------

    function lane(id, payload) {
        var $el = $('#' + id);
        $el.html('<span class="text-muted">…loading…</span>');
        return post(payload)
            .then(function (res) { return { id: id, res: res }; })
            .catch(function ()    { return { id: id, res: { result: 'failed' } }; });
    }

    function renderDmarc(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var p = res.posture || {};
        var v = (p.recommendation && p.recommendation.verdict) || 'unknown';
        var msg = (p.recommendation && p.recommendation.message) || '';
        var dmarc = p.dmarc && p.dmarc.policy ? 'DMARC p=' + esc(p.dmarc.policy) : 'no DMARC';
        var spf = p.spf && p.spf.all ? 'SPF ' + esc(p.spf.all) : 'no SPF';
        var badge = ({
            'hardened':            'success',
            'partially-hardened':  'info',
            'monitoring':          'warning',
            'spf-only-strict':     'warning',
            'wide-open':           'danger',
            'unknown':             'secondary'
        })[v] || 'secondary';
        return '<span class="badge badge-' + badge + '">' + esc(v) + '</span>'
            + '<div class="mt-2">' + dmarc + ' &middot; ' + spf + '</div>'
            + (msg ? '<div class="text-muted mt-1">' + esc(msg) + '</div>' : '');
    }

    function renderMx(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var mx = res.mx || {};
        var prim = mx.primary || {};
        var label = prim.label || '—';
        var cat = prim.category || 'unknown';
        var preferred = mx.pretext_categories || [];
        var cats = preferred.slice(0, 3).join(' &middot; ');
        // Phase 3.43c: kick off Step 3 once we know which categories to prioritise.
        runPretextPicker(preferred);
        return '<strong>' + esc(label) + '</strong>'
            + '<div class="text-muted">' + esc(cat) + ' &middot; ' + (mx.count || 0) + ' MX</div>'
            + '<div class="mt-2"><span class="text-muted">Suggested pretexts:</span> ' + cats + '</div>';
    }

    function runPretextPicker(categories) {
        $('#step3_wrap').show();
        $('#step3_categories').text('Preferred categories: ' + (categories.length ? categories.join(' › ') : '(no preference)'));
        $('#step3_pretexts').html('<div class="col-12 text-muted">…loading…</div>');
        post({ action_type: 'list_pretexts_ranked', categories: categories, limit: 8 })
            .done(function (res) {
                if (!res || res.result !== 'success') {
                    $('#step3_pretexts').html('<div class="col-12 text-danger">Could not load pretext library.</div>');
                    return;
                }
                renderPretextPicks(res.pretexts || []);
            })
            .fail(function () {
                $('#step3_pretexts').html('<div class="col-12 text-danger">Could not load pretext library.</div>');
            });
    }

    function renderPretextPicks(pretexts) {
        var $g = $('#step3_pretexts').empty();
        if (!pretexts.length) {
            $g.html('<div class="col-12 text-muted">No pretexts in the library yet.</div>');
            return;
        }
        pretexts.forEach(function (p) {
            var $col = $('<div class="col-md-6 col-lg-4 mb-3">');
            var $card = $('<div class="card h-100"><div class="card-body"></div></div>').appendTo($col);
            var $body = $card.find('.card-body');
            $body.append(
                $('<span class="badge badge-info mr-2"></span>').text(p.category || ''),
                $('<strong></strong>').text(p.name || ''),
                $('<div class="small text-muted mt-1"></div>').text(p.subject || ''),
                $('<button class="btn btn-sm btn-info mt-3"><i class="fa fa-clone"></i> Clone to my templates</button>')
                    .on('click', function () { clonePretext(p.id, $(this)); })
            );
            $g.append($col);
        });
    }

    function clonePretext(pretextId, $btn) {
        $btn.prop('disabled', true);
        post({ action_type: 'clone_pretext_to_my_templates', pretext_id: pretextId })
            .done(function (res) {
                if (res && res.result === 'success') {
                    if (window.toastr) toastr.success('Cloned. Open Email Template to edit.');
                    $btn.replaceWith(
                        $('<a class="btn btn-sm btn-success mt-3"></a>')
                            .attr('href', 'MailTemplate?action=edit&mail_template_id=' + res.mail_template_id)
                            .html('<i class="fa fa-check"></i> Open my copy')
                    );
                } else {
                    if (window.toastr) toastr.error((res && res.error) || 'Clone failed');
                    $btn.prop('disabled', false);
                }
            })
            .fail(function () {
                if (window.toastr) toastr.error('Clone failed');
                $btn.prop('disabled', false);
            });
    }

    function renderHomoglyph(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var c = (res.candidates || []).slice(0, 6);
        if (!c.length) return '<span class="text-muted">no candidates</span>';
        return '<ol class="mb-0 pl-3">' + c.map(function (x) {
            return '<li><code>' + esc(x.domain) + '</code> <span class="text-muted">'
                + esc(x.kind) + ' &middot; score ' + (x.score || 0).toFixed(2) + '</span></li>';
        }).join('') + '</ol>';
    }

    function renderHunter(res) {
        if (!res) return '<span class="text-danger">lookup failed</span>';
        if (res.result !== 'success') {
            var err = res.err || res.error || '';
            if (/api\s*key/i.test(err)) {
                return '<span class="text-muted">Hunter.io API key not configured</span>'
                    + '<div class="small text-muted mt-1">'
                    + 'Add one in <a href="SettingsGeneral">Settings → General</a> to enable email-format guessing.'
                    + '</div>';
            }
            return '<span class="text-danger">' + esc(err || 'lookup failed') + '</span>';
        }
        var org = res.organization || '';
        var results = Array.isArray(res.results) ? res.results.slice(0, 4) : [];
        if (!org && !results.length) return '<span class="text-muted">no data</span>';
        return (org ? '<strong>' + esc(org) + '</strong>' : '')
            + (results.length ? '<ul class="mb-0 pl-3 mt-1">' + results.map(function (r) {
                var who = (r.name || (r.first_name + ' ' + r.last_name)).trim();
                return '<li><code>' + esc(r.email || '') + '</code>'
                    + (who ? ' <span class="text-muted">— ' + esc(who) + '</span>' : '')
                    + '</li>';
            }).join('') + '</ul>' : '');
    }

    function renderSubdomains(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var subs = res.subdomains || res.domains || res.results || [];
        if (!Array.isArray(subs) || !subs.length) return '<span class="text-muted">no subdomains found</span>';
        return '<span class="text-muted">' + subs.length + ' found</span>'
            + '<ul class="mb-0 pl-3 mt-1">' + subs.slice(0, 6).map(function (s) {
                return '<li><code>' + esc(s) + '</code></li>';
            }).join('') + '</ul>';
    }

    function renderWeb(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var w = res.web || {};
        if (!w.reachable) return '<span class="text-warning">site unreachable (status ' + (w.status || 0) + ')</span>';
        var parts = [];
        if (w.title) parts.push('<strong>' + esc(w.title) + '</strong>');
        if (w.generator) parts.push('<span class="text-muted">generator: ' + esc(w.generator) + '</span>');
        var rob = w.robots || {};
        if (rob.present) parts.push('robots: ' + (rob.sitemaps.length ? rob.sitemaps.length + ' sitemap(s)' : 'no sitemap')
            + (rob.disallow_hits.length ? ' &middot; ' + rob.disallow_hits.length + ' disallow' : ''));
        var sec = w.security_txt || {};
        if (sec.present) parts.push('security.txt: ' + (sec.contact.length ? esc(sec.contact[0]) : 'no contact'));
        return parts.length ? parts.join('<br>') : '<span class="text-muted">no data</span>';
    }

    function runOsint(domain) {
        domain = (domain || '').trim().toLowerCase();
        if (!domain) {
            if (window.toastr) toastr.warning('Enter a target domain first');
            return;
        }
        $('#osint_panel').show();
        Object.entries({
            osint_dmarc:      { action_type: 'email_posture_lookup', domain: domain },
            osint_mx:         { action_type: 'mx_classify_domain',   domain: domain },
            osint_homoglyph:  { action_type: 'homoglyph_candidates', domain: domain, limit: 30 },
            osint_subdomains: { action_type: 'osint_crt_sh_subdomains', domain: domain },
            osint_hunter:    { action_type: 'osint_hunter_search',     domain: domain, limit: 15 },
            osint_web:        { action_type: 'web_fingerprint',      domain: domain }
        }).forEach(function (kv) {
            lane(kv[0], kv[1]).then(function (out) {
                var renderer = ({
                    osint_dmarc:      renderDmarc,
                    osint_mx:         renderMx,
                    osint_homoglyph:  renderHomoglyph,
                    osint_subdomains: renderSubdomains,
                    osint_hunter:    renderHunter,
                    osint_web:        renderWeb
                })[out.id];
                $('#' + out.id).html(renderer ? renderer(out.res) : '—');
            });
        });
    }

    $(function () {
        $('#frm_engagement').on('submit', onSubmit);
        $('#btn_refresh_eng').on('click', refreshList);
        $('#btn_osint_run').on('click', function () { runOsint($('#osint_domain').val()); });
        $('#btn_osint_use_from_eng').on('click', function (e) {
            e.preventDefault();
            var first = ($('#eng_scope').val() || '').split(/[\s,;]+/)[0] || '';
            if (first) {
                $('#osint_domain').val(first);
                runOsint(first);
            } else if (window.toastr) toastr.warning('Enter at least one authorised domain in Step 1 first');
        });
        $('#osint_domain').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); runOsint($(this).val()); }
        });
        refreshList();
    });
})();
