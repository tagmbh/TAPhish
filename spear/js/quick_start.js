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

    function statusBadge(status) {
        return ({
            'draft':     'badge-secondary',
            'live':      'badge-info',
            'completed': 'badge-success',
            'cancelled': 'badge-danger'
        })[status] || 'badge-secondary';
    }

    function renderRecent(rows) {
        var $body = $('#tb_engagements tbody').empty();
        if (!rows || !rows.length) {
            $body.append('<tr><td colspan="5" class="text-muted">No engagements yet.</td></tr>');
            return;
        }
        rows.forEach(function (e) {
            var scope = (e.scope_allowlist || []).slice(0, 3).join(', ');
            if ((e.scope_allowlist || []).length > 3) scope += ' …';
            var status = e.status || 'draft';
            var $tr = $('<tr>');
            $tr.append($('<td>').append($('<code>').text(e.slug)));
            $tr.append($('<td>').addClass('small').text(
                (e.start_at || '—') + ' → ' + (e.end_at || '—')
            ));
            $tr.append($('<td>').addClass('small text-muted').text(scope || '—'));
            $tr.append($('<td>').append(
                $('<span>').addClass('badge ' + statusBadge(status)).text(status)
            ));
            // Phase 3.56: a draft engagement that hasn't reached Step 7 gets a
            // "Continue setup" deep-link that resumes the wizard where it left
            // off; anything else just opens its EngagementView.
            var resumable = (status === 'draft') && ((parseInt(e.wizard_step, 10) || 1) < 7);
            $tr.append($('<td>').addClass('text-right').append(
                resumable
                    ? $('<a class="btn btn-sm btn-info">')
                        .attr('href', 'QuickStart?engagement_id=' + e.id)
                        .html('<i class="fa fa-play"></i> Continue setup')
                    : $('<a class="btn btn-sm btn-outline-secondary">')
                        .attr('href', 'EngagementView?engagement_id=' + e.id)
                        .text('Open')
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

        // Phase 3.46-pre: capture scope BEFORE reset so we can cascade
        // the first domain into the Step 2 OSINT lane and derive the
        // DKIM selector from the engagement slug.
        var savedScope = ($('#eng_scope').val() || '').split(/[\s,;]+/)
            .map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);

        post({ action_type: 'save_engagement', payload: readForm() })
            .done(function (res) {
                if (res && res.result === 'success') {
                    // Phase 3.56: hand the new engagement id to the stepflow
                    // controller, which captures it and advances to Step 2.
                    if (res.engagement_id) {
                        $('#wizard_engagement_id').val(res.engagement_id);
                        $(document).trigger('wizard:saved', [res.engagement_id]);
                    }
                    $('#eng_result').html(
                        '<div class="alert alert-success">' +
                        '<strong>Saved.</strong> Slug: <code>' + esc(res.slug) + '</code>. ' +
                        'The wizard auto-filled the OSINT target + DKIM selector below; just hit the buttons.' +
                        '</div>'
                    );
                    $('#frm_engagement')[0].reset();
                    refreshList();
                    if (window.toastr) toastr.success('Engagement saved');

                    // Cascade defaults so the operator can click through
                    // each step without retyping the same data.
                    if (savedScope.length && !$('#osint_domain').val()) {
                        $('#osint_domain').val(savedScope[0]);
                    }
                    // Only overwrite the selector if it's still the default
                    // (s1/s2/...). Slug is DNS-safe; trim to ≤16 chars so
                    // the selector + ._domainkey label stays well under
                    // the 63-byte DNS label limit.
                    if (res.slug && /^s\d+$/.test($('#dkim_selector').val() || 's1')) {
                        var sel = String(res.slug).replace(/[^a-z0-9-]/g, '').slice(0, 16) || 's1';
                        $('#dkim_selector').val(sel);
                    }
                    // Auto-run OSINT pre-check so the operator sees the
                    // lanes populate without an extra click.
                    if (savedScope.length) {
                        setTimeout(function () { runOsint(savedScope[0]); }, 200);
                    }
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

    function skeleton(lines) {
        var n = lines || 3;
        var out = '';
        for (var i = 0; i < n; i++) {
            out += '<div class="t-skel' + (i === 0 ? ' is-tall' : (i === n - 1 ? ' is-short' : '')) + '"></div>';
        }
        return out;
    }

    function lane(id, payload) {
        var $el = $('#' + id);
        $el.html(skeleton(3));
        return post(payload)
            .then(function (res) { return { id: id, res: res }; })
            .catch(function ()    { return { id: id, res: { result: 'failed' } }; });
    }

    function setStepperState(maxDone) {
        // 1 = engagement saved, 2 = OSINT triggered, 3 = pretexts shown, etc.
        var $items = $('#t_stepper > li');
        $items.removeClass('is-active is-done');
        $items.each(function (idx) {
            var step = idx + 1;
            if (step < maxDone) $(this).addClass('is-done');
            else if (step === maxDone) $(this).addClass('is-active');
        });
    }

    function renderDmarc(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var p = res.posture || {};
        // Bug fix: taphish_lookup_email_posture returns a FLAT shape —
        // verdict + recommendation are top-level strings; the DMARC
        // policy is dmarc.p (not dmarc.policy); the SPF "all" qualifier
        // is spf.qualifier_all (not spf.all). The old code read nested
        // keys that never existed, so every domain showed "no DMARC /
        // no SPF" even when records were present (e.g. t-alpha.ch).
        var v = p.verdict || 'unknown';
        var msg = typeof p.recommendation === 'string' ? p.recommendation : '';
        var dmarcRaw = (typeof p.dmarc_raw === 'string') ? p.dmarc_raw : '';
        var dmarcPolicy = (p.dmarc && p.dmarc.p) ? p.dmarc.p : '';
        var dmarc = dmarcRaw !== ''
            ? 'DMARC' + (dmarcPolicy ? ' p=' + esc(dmarcPolicy) : '')
            : 'no DMARC';
        var spfRaw = (typeof p.spf_raw === 'string') ? p.spf_raw : '';
        var spfAll = (p.spf && p.spf.qualifier_all) ? p.spf.qualifier_all : '';
        var spf = spfRaw !== ''
            ? 'SPF' + (spfAll ? ' ' + esc(spfAll) + 'all' : '')
            : 'no SPF';
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
        // Phase 3.56: populate Step 3 in place (the stepflow controller owns
        // when the wrap becomes visible + the stepper highlight).
        $('#step3_categories').text('Preferred categories: ' + (categories.length ? categories.join(' › ') : '(no preference)'));
        $('#step3_pretexts').html(
            '<div class="col-md-6 col-lg-4 mb-3"><div class="card h-100"><div class="card-body">' + skeleton(4) + '</div></div></div>' +
            '<div class="col-md-6 col-lg-4 mb-3"><div class="card h-100"><div class="card-body">' + skeleton(4) + '</div></div></div>' +
            '<div class="col-md-6 col-lg-4 mb-3"><div class="card h-100"><div class="card-body">' + skeleton(4) + '</div></div></div>'
        );
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

    // Phase 3.46-pre: Shodan host lane. Key lives in localStorage —
    // the operator pastes it once and we send it inline per call. The
    // lane silently degrades to "key not configured" when missing so a
    // fresh install isn't flooded with red.
    function renderShodan(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var s = res.shodan || {};
        if (!s.ok) {
            var err = s.err || 'lookup failed';
            if (/Invalid Shodan API key/.test(err) || /no API key/.test(err)) {
                return '<span class="text-muted">key not configured — click <code>key</code> above to paste yours</span>';
            }
            return '<span class="text-warning">' + esc(err) + '</span>';
        }
        var head = '<code>' + esc(s.ip || '—') + '</code>';
        if (s.org) head += ' &middot; ' + esc(s.org);
        if (s.country) head += ' (' + esc(s.country) + ')';
        var ports = (s.open_ports || []).slice(0, 12);
        var portsHtml = ports.length
            ? '<div class="mt-1">' + ports.map(function (p) {
                return '<span class="badge badge-secondary mr-1">' + esc(String(p)) + '</span>';
              }).join('') + '</div>'
            : '<div class="text-muted mt-1">no open ports reported</div>';
        var vulnsHtml = (s.vulns || []).length
            ? '<div class="mt-1"><span class="text-danger">vulns: </span>'
                + s.vulns.map(function (v) { return '<code>' + esc(v) + '</code>'; }).join(', ')
                + '</div>'
            : '';
        var when = s.last_update ? '<div class="text-muted small mt-1">last seen: ' + esc(s.last_update.substring(0, 10)) + '</div>' : '';
        return head + portsHtml + vulnsHtml + when;
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
        var shodanKey = '';
        try { shodanKey = (localStorage.getItem('taphish_shodan_key') || '').trim(); } catch (_) {}
        var lanes = {
            osint_dmarc:      { action_type: 'email_posture_lookup', domain: domain },
            osint_mx:         { action_type: 'mx_classify_domain',   domain: domain },
            osint_homoglyph:  { action_type: 'homoglyph_candidates', domain: domain, limit: 30 },
            osint_subdomains: { action_type: 'osint_crt_sh_subdomains', domain: domain },
            osint_hunter:     { action_type: 'osint_hunter_search',  domain: domain, limit: 15 },
            osint_web:        { action_type: 'web_fingerprint',      domain: domain },
            osint_shodan:     { action_type: 'osint_shodan_host',    domain: domain, api_key: shodanKey }
        };
        Object.entries(lanes).forEach(function (kv) {
            lane(kv[0], kv[1]).then(function (out) {
                var renderer = ({
                    osint_dmarc:      renderDmarc,
                    osint_mx:         renderMx,
                    osint_homoglyph:  renderHomoglyph,
                    osint_subdomains: renderSubdomains,
                    osint_hunter:     renderHunter,
                    osint_web:        renderWeb,
                    osint_shodan:     renderShodan
                })[out.id];
                $('#' + out.id).html(renderer ? renderer(out.res) : '—');
            });
        });
    }

    // Phase 3.46-pre: Shodan API key prompt. localStorage only — never
    // leaves the browser except inline in the lane request. Empty input
    // clears the saved key.
    function manageShodanKey() {
        var current = '';
        try { current = localStorage.getItem('taphish_shodan_key') || ''; } catch (_) {}
        var masked = current ? current.substring(0, 4) + '…' + current.substring(current.length - 4) : '(none)';
        var v = window.prompt('Shodan API key (32 alphanumeric). Current: ' + masked + '\nLeave empty to clear.', '');
        if (v === null) return;
        v = v.trim();
        try {
            if (v === '') localStorage.removeItem('taphish_shodan_key');
            else localStorage.setItem('taphish_shodan_key', v);
        } catch (_) {}
        if (window.toastr) {
            toastr.success(v === '' ? 'Shodan key cleared' : 'Shodan key saved');
        }
    }

    // ----- Step 4: Sender setup (DKIM) -----------------------------------

    function renderDkim(res) {
        if (!res || res.result !== 'success') {
            return '<div class="alert alert-danger">' + esc((res && res.error) || 'DKIM gen failed') + '</div>';
        }
        var selector = res.selector || 's1';
        var dkimHost = '<code>' + esc(selector) + '._domainkey.&lt;your-look-alike-domain&gt;</code>';
        return ''
            + '<div class="alert alert-info mt-3">'
            +   '<strong>Generated.</strong> Publish three TXT records at the look-alike domain. '
            +   'Keep the private key safe — it never leaves this page after you copy it.'
            + '</div>'
            + '<h6 class="mt-3">DKIM TXT @ ' + dkimHost + '</h6>'
            + '<pre class="mb-2 p-2 bg-dark text-light small" style="white-space:pre-wrap;">' + esc(res.txt_record) + '</pre>'
            + '<h6 class="mt-3">SPF TXT @ <code>&lt;your-look-alike-domain&gt;</code></h6>'
            + '<pre class="mb-2 p-2 bg-dark text-light small">' + esc(res.spf_record) + '</pre>'
            + '<h6 class="mt-3">DMARC TXT @ <code>_dmarc.&lt;your-look-alike-domain&gt;</code></h6>'
            + '<pre class="mb-2 p-2 bg-dark text-light small">' + esc(res.dmarc_record) + '</pre>'
            + '<h6 class="mt-3">Private key (paste into your sender config — do not commit)</h6>'
            + '<pre class="mb-2 p-2 bg-dark text-light small" style="white-space:pre-wrap;">' + esc(res.private_key_pem) + '</pre>';
    }

    function runDkimGen() {
        $('#dkim_result').html(skeleton(3));
        post({
            action_type: 'wizard_generate_dkim',
            selector: ($('#dkim_selector').val() || 's1').trim(),
            dmarc_rua: ($('#dkim_rua').val() || '').trim()
        })
            .done(function (res) { $('#dkim_result').html(renderDkim(res)); })
            .fail(function ()    { $('#dkim_result').html('<div class="alert alert-danger">Request failed</div>'); });
    }

    // ----- Step 5: Recipient preview -------------------------------------

    function renderRecipientPreview(res) {
        if (!res || res.result !== 'success') {
            return '<div class="alert alert-danger">' + esc((res && res.error) || 'Preview failed') + '</div>';
        }
        var goodCount = res.row_count || 0;
        var parseErrCount = (res.parse_errors || []).length;
        var scopeErrCount = (res.scope_violations || []).length;
        var html = '<div class="alert alert-success mb-2"><strong>' + goodCount + '</strong> parseable row(s).</div>';
        var breakdown = res.domain_breakdown || {};
        var domains = Object.keys(breakdown);
        if (domains.length) {
            html += '<div class="mt-2"><strong>Per-domain breakdown:</strong></div><ul class="mb-2 small">';
            domains.slice(0, 12).forEach(function (d) {
                html += '<li><code>' + esc(d) + '</code> — ' + breakdown[d] + '</li>';
            });
            html += '</ul>';
        }
        if (parseErrCount) {
            html += '<div class="alert alert-warning mt-2"><strong>' + parseErrCount + '</strong> parse error(s) — these rows will be skipped:';
            html += '<ul class="mb-0 small mt-1">';
            (res.parse_errors || []).slice(0, 12).forEach(function (e) {
                html += '<li>Line ' + esc(e.line) + ': ' + esc(e.email || '(blank)') + ' — ' + esc(e.reason) + '</li>';
            });
            html += '</ul></div>';
        }
        if (scopeErrCount) {
            html += '<div class="alert alert-danger mt-2"><strong>' + scopeErrCount + '</strong> recipient(s) are <em>out of engagement scope</em> and will be skipped:';
            html += '<ul class="mb-0 small mt-1">';
            (res.scope_violations || []).slice(0, 12).forEach(function (v) {
                html += '<li><code>' + esc(v.email) + '</code> (domain ' + esc(v.domain) + ' not in allowlist)</li>';
            });
            html += '</ul></div>';
        }
        if (!parseErrCount && !scopeErrCount) {
            html += '<div class="alert alert-info mt-2">All rows look good. Open <a href="MailUserGroup">Mail User Group</a> to actually persist them; the wizard auto-applies engagement scope on upload (Phase 3.45c).</div>';
        }
        return html;
    }

    function runRecipientPreview() {
        var csv = $('#rcpt_csv').val() || '';
        if (csv.trim() === '') {
            if (window.toastr) toastr.warning('Paste a CSV first');
            return;
        }
        $('#rcpt_preview_result').html(skeleton(3));
        post({ action_type: 'wizard_recipient_preview', user_data: csv })
            .done(function (res) { $('#rcpt_preview_result').html(renderRecipientPreview(res)); })
            .fail(function ()    { $('#rcpt_preview_result').html('<div class="alert alert-danger">Request failed</div>'); });
    }

    // ----- Step 6: Landing page picker -----------------------------------

    function loadLandingOptions() {
        $('#landing_options').html(skeleton(3));
        post({ action_type: 'wizard_list_landing_options' })
            .done(function (res) {
                if (!res || res.result !== 'success') {
                    $('#landing_options').html('<div class="alert alert-danger">Could not load landing options.</div>');
                    return;
                }
                var html = '';
                html += '<div class="col-md-4 mb-3"><div class="card h-100"><div class="card-body">'
                     +    '<h6>Clone via Site Cloner</h6>'
                     +    '<p class="small text-muted">Fetch a target URL and rewrite assets.</p>'
                     +    '<a class="btn btn-sm btn-info" href="SiteCloner">Open Site Cloner</a>'
                     +    (res.clones && res.clones.length ? '<div class="mt-2 small text-muted">Existing slugs: ' + res.clones.map(esc).slice(0, 6).join(', ') + (res.clones.length > 6 ? ', …' : '') + '</div>' : '')
                     +  '</div></div></div>';
                html += '<div class="col-md-4 mb-3"><div class="card h-100"><div class="card-body">'
                     +    '<h6>AI-generate</h6>'
                     +    '<p class="small text-muted">Describe the page; Claude builds it.</p>'
                     +    '<a class="btn btn-sm btn-info" href="sniperhost/LandingPage?action=ai">Open Landing Page editor</a>'
                     +  '</div></div></div>';
                html += '<div class="col-md-4 mb-3"><div class="card h-100"><div class="card-body">'
                     +    '<h6>Library shortcuts</h6>'
                     +    '<p class="small text-muted mb-2">Curated templates (multi-step / single-page / SSO-redirect). Each is a starting point; customize per engagement.</p>'
                     +    '<ul class="small mb-2 pl-3">'
                     +    (res.library || []).map(function (l) {
                            var tag = l.has_2fa ? ' <span class="badge badge-success">+2FA</span>' : '';
                            return '<li><strong>' + esc(l.label) + '</strong>' + tag +
                                   '<br><span class="text-muted">' + esc(l.pattern || '') + '</span></li>';
                          }).join('')
                     +    '</ul>'
                     +    '<a class="btn btn-sm btn-info" href="LandingLibrary">Open library</a>'
                     +  '</div></div></div>';
                $('#landing_options').html(html);
            })
            .fail(function () { $('#landing_options').html('<div class="alert alert-danger">Request failed</div>'); });
    }

    // ----- Step 7: Pre-flight + Launch -----------------------------------

    function gatherPreflightContext() {
        var emails = ($('#pf_emails').val() || '').split(/[\s,;]+/).map(function (e) { return e.trim().toLowerCase(); }).filter(Boolean);
        var scope = ($('#eng_scope').val() || '').split(/[\s,;]+/).map(function (e) { return e.trim().toLowerCase(); }).filter(Boolean);
        return {
            recipient_emails:    emails,
            scope_allowlist:     scope,
            sender_domain:       ($('#pf_sender_domain').val() || '').trim().toLowerCase(),
            target_domain:       ($('#pf_target_domain').val() || '').trim().toLowerCase(),
            target_dmarc_policy: $('#pf_dmarc').val(),
            webhook_url:         ($('#pf_webhook').val() || '').trim(),
            landing_url:         ($('#pf_landing_url').val() || '').trim(),
            rendered_mail_body:  ($('#pf_rendered_body').val() || ''),
        };
    }

    function renderPreflight(report) {
        var html = '<table class="table table-sm mt-3"><thead><tr><th>Gate</th><th>Verdict</th><th>Reason</th></tr></thead><tbody>';
        var gates = report.gates || {};
        Object.keys(gates).forEach(function (key) {
            var g = gates[key];
            var badge = g.ok ? 'badge-success' : 'badge-danger';
            var verdict = g.ok ? 'pass' : 'fail';
            html += '<tr>'
                 + '<td>' + esc(key) + '</td>'
                 + '<td><span class="badge ' + badge + '">' + verdict + '</span></td>'
                 + '<td class="small text-muted">' + esc(g.reason || '') + '</td>'
                 + '</tr>';
        });
        html += '</tbody></table>';
        if (report.ok) {
            html += '<div class="alert alert-success">All gates green. Launch button enabled.</div>';
        } else {
            html += '<div class="alert alert-warning">One or more gates failed — fix above before launch.</div>';
        }
        return html;
    }

    function runPreflight() {
        var ctx = gatherPreflightContext();
        $('#preflight_result').html(skeleton(4));
        $('#btn_launch').prop('disabled', true);
        post({ action_type: 'wizard_preflight', context: ctx })
            .done(function (res) {
                if (!res || res.result !== 'success') {
                    $('#preflight_result').html('<div class="alert alert-danger">Pre-flight failed</div>');
                    return;
                }
                $('#preflight_result').html(renderPreflight(res));
                $('#btn_launch').prop('disabled', !res.ok);
            })
            .fail(function () { $('#preflight_result').html('<div class="alert alert-danger">Request failed</div>'); });
    }

    function runLaunch() {
        var engId = parseInt($('#wizard_engagement_id').val(), 10) || 0;
        // Best-effort: pick the most recent engagement from the recent list
        // if no explicit id was carried.
        if (!engId) {
            var firstRow = $('#tb_engagements tbody tr:first');
            if (firstRow.length) {
                // Re-fetch via list_engagements would be cleaner; fall back to a prompt.
                var slug = firstRow.find('code').text();
                if (slug && window.toastr) toastr.info('Launching for engagement ' + slug + ' (id resolved server-side)');
            }
        }
        var ctx = gatherPreflightContext();
        ctx.campaign_name = 'Wizard-launched ' + new Date().toISOString().slice(0, 16);
        ctx.scheduled_time = '';
        ctx.camp_status = 0;
        post({ action_type: 'wizard_launch_campaign', engagement_id: engId, context: ctx })
            .done(function (res) {
                if (res && res.result === 'success') {
                    if (window.toastr) toastr.success('Launched. Campaign ' + res.campaign_id);
                    $(document).trigger('wizard:launched');
                    window.location.href = 'EngagementView?engagement_id=' + res.engagement_id;
                } else {
                    if (window.toastr) toastr.error((res && res.error) || 'Launch rejected');
                }
            })
            .fail(function () { if (window.toastr) toastr.error('Request failed'); });
    }

    // Phase 3.56: surface the per-step entry points the stepflow controller
    // (wizard_stepflow.js, loaded right after this file) drives. Navigation
    // and progress persistence live there; this file still owns what each
    // step actually DOES.
    window.TAPhishWizard = {
        post:                post,
        setStepperState:     setStepperState,
        loadLandingOptions:  loadLandingOptions,
        runOsint:            runOsint,
        runDkimGen:          runDkimGen,
        runRecipientPreview: runRecipientPreview,
        runPreflight:        runPreflight,
        runLaunch:           runLaunch,
        refreshList:         refreshList
    };

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
        $('#btn_shodan_key').on('click', function (e) { e.preventDefault(); manageShodanKey(); });
        // Phase 3.45c: Step 4 + Step 5 wiring.
        $('#btn_gen_dkim').on('click', runDkimGen);
        $('#btn_rcpt_preview').on('click', runRecipientPreview);
        // Phase 3.45d: Step 6 + Step 7 wiring. (Phase 3.56: the stepflow
        // controller owns step visibility and lazy-loads the landing options
        // when Step 6 is shown, so we no longer force every wrap visible here.)
        $('#btn_run_preflight').on('click', runPreflight);
        $('#btn_launch').on('click', runLaunch);
        refreshList();
    });
})();
