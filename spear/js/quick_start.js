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

    // The engagement id lives in a hidden input shared with wizard_stepflow.js.
    // Both files are separate IIFEs, so each needs its own accessor.
    function engId() {
        return parseInt($('#wizard_engagement_id').val(), 10) || 0;
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

    // A: pre-fill the engagement window (start = now, end = +14d) and keep
    // the native datetime-local picker; B: live-render the parsed authorised
    // domains as chips so the operator sees exactly what will be saved.
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function toLocalInput(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
            + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    function prefillWindow() {
        if (!$('#eng_start').val()) $('#eng_start').val(toLocalInput(new Date()));
        if (!$('#eng_end').val()) {
            var end = new Date(); end.setDate(end.getDate() + 14);
            $('#eng_end').val(toLocalInput(end));
        }
    }
    function parseScopeDomains() {
        return ($('#eng_scope').val() || '').split(/[\s,;]+/)
            .map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    }
    function renderScopeChips() {
        var $box = $('#eng_scope_chips'); if (!$box.length) return;
        var domains = parseScopeDomains();
        if (!domains.length) { $box.empty(); return; }
        var seen = {};
        $box.html(domains.filter(function (d) { if (seen[d]) return false; seen[d] = 1; return true; })
            .map(function (d) {
                var ok = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/.test(d);
                return '<span class="badge ' + (ok ? 'badge-info' : 'badge-warning') + ' mr-1 mb-1">'
                    + esc(d) + (ok ? '' : ' ?') + '</span>';
            }).join(''));
    }

    function onSubmit(e) {
        e.preventDefault();
        clearFieldErrors();
        $('#eng_result').empty();
        // A: the window end must be after the start (datetime-local values
        // sort lexicographically = chronologically).
        var _st = $('#eng_start').val(), _en = $('#eng_end').val();
        if (_st && _en && _en <= _st) {
            markFieldError('eng_end', 'The window end must be after the start.');
            $('#eng_result').html('<div class="alert alert-danger">Please fix the highlighted fields.</div>');
            return;
        }
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
        var cats = preferred.slice(0, 3).map(esc).join(' &middot; ');
        // Full-funnel wizard: the pretext picker now lives in Step 5 (mail
        // template) and loads lazily when that step is shown.
        return '<strong>' + esc(label) + '</strong>'
            + '<div class="text-muted">' + esc(cat) + ' &middot; ' + (mx.count || 0) + ' MX</div>'
            + '<div class="mt-2"><span class="text-muted">Suggested pretexts:</span> ' + cats + '</div>';
    }


    function renderHomoglyph(res) {
        if (!res || res.result !== 'success') return '<span class="text-danger">lookup failed</span>';
        var c = (res.candidates || []).slice(0, 6);
        if (!c.length) return '<span class="text-muted">no registrable candidates</span>';
        // Candidates come from homoglyph_check_candidates: validated via
        // Hostpoint's domain-check, enriched with the punycode (name_idna) form.
        // Each gets a "register at Hostpoint" deep-link (paste the shown name).
        var REG = 'https://www.hostpoint.ch/en/domains/domains.html';
        var rows = c.map(function (x) {
            var idna = (x.name_idna && x.name_idna !== x.domain)
                ? ' <span class="text-muted">(' + esc(x.name_idna) + ')</span>' : '';
            return '<li><code>' + esc(x.domain) + '</code>' + idna
                + ' <span class="text-muted">' + esc(x.kind) + ' &middot; score ' + (x.score || 0).toFixed(2) + '</span>'
                + ' <a href="' + REG + '" target="_blank" rel="noopener noreferrer" class="small">register →</a></li>';
        }).join('');
        return '<div class="small text-muted mb-1">Valid registrable look-alikes (checked on Hostpoint):</div>'
            + '<ol class="mb-0 pl-3">' + rows + '</ol>';
    }

    function renderHunter(res) {
        if (!res) return '<span class="text-danger">lookup failed</span>';
        if (res.result !== 'success') {
            var err = res.err || res.error || '';
            if (/api\s*key/i.test(err)) {
                // Distinguish "no key saved" from "a key IS saved but Hunter
                // rejected it" — otherwise an operator who configured a key in
                // Settings is wrongly told to add one (the reported confusion).
                var hasKey = false;
                try { hasKey = !!(localStorage.getItem('taphish_hunter_apikey') || '').trim(); } catch (_) {}
                if (hasKey) {
                    return '<span class="text-warning">Hunter.io rejected the configured API key</span>'
                        + '<div class="small text-muted mt-1">'
                        + 'Check / re-enter it in <a href="SettingsGeneral">Settings → General</a> (it may be wrong, expired, or rate-limited).'
                        + '</div>';
                }
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
        var hunterKey = '';
        try { shodanKey = (localStorage.getItem('taphish_shodan_key') || '').trim(); } catch (_) {}
        // Hunter key is saved browser-side by Settings → General under this
        // localStorage name; the lane must forward it or the server sees no key.
        try { hunterKey = (localStorage.getItem('taphish_hunter_apikey') || '').trim(); } catch (_) {}
        var lanes = {
            osint_dmarc:      { action_type: 'email_posture_lookup', domain: domain },
            osint_mx:         { action_type: 'mx_classify_domain',   domain: domain },
            osint_homoglyph:  { action_type: 'homoglyph_check_candidates', domain: domain },
            osint_subdomains: { action_type: 'osint_crt_sh_subdomains', domain: domain },
            osint_hunter:     { action_type: 'osint_hunter_search',  domain: domain, limit: 15, api_key: hunterKey },
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

    // =====================================================================
    // Full-funnel wizard state. These IDs are committed step-by-step and
    // persisted via wizard_stepflow.js (collectState reads window.TAPhishWizard.state).
    // =====================================================================
    var WZ = {
        user_group_id:    '',
        recipient_emails: [],   // in-scope committed emails (for launch ctx)
        tracker_id:       '',
        tracker_mod_url:  '',
        clone_slug:       '',
        landing_url:      '',
        mail_template_id: '',
        rendered_body:    '',
        sender_list_id:   '',
        sender_from:      ''
    };

    // ----- Step 3: Recipients (preview + commit) -------------------------

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
            html += '<div class="alert alert-info mt-2">All rows look good. Hit <strong>Commit recipients</strong> to persist them on this engagement.</div>';
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
        post({ action_type: 'wizard_recipient_preview', user_data: csv, engagement_id: engId() })
            .done(function (res) {
                $('#rcpt_preview_result').html(renderRecipientPreview(res));
                // Stash the in-scope emails so a later commit can carry them to launch.
                if (res && res.result === 'success') {
                    var bad = {};
                    (res.scope_violations || []).forEach(function (v) {
                        if (typeof v.line_index !== 'undefined') bad[v.line_index] = true;
                    });
                    WZ.recipient_emails = (res.rows || [])
                        .filter(function (_r, i) { return !bad[i]; })
                        .map(function (r) { return (r.email || '').toLowerCase(); })
                        .filter(Boolean);
                }
            })
            .fail(function ()    { $('#rcpt_preview_result').html('<div class="alert alert-danger">Request failed</div>'); });
    }

    function runRecipientCommit() {
        var id = engId();
        if (!id) { if (window.toastr) toastr.warning('Save the engagement first'); return; }
        var groupName = ($('#rcpt_group_name').val() || '').trim();
        var csv = $('#rcpt_csv').val() || '';
        if (groupName === '') { if (window.toastr) toastr.warning('Enter a group name'); $('#rcpt_group_name').focus(); return; }
        if (csv.trim() === '') { if (window.toastr) toastr.warning('Paste a CSV first'); return; }
        // Commit parses + scope-filters the CSV server-side and returns the
        // in-scope emails it stored, so one request covers both the persist and
        // the Step 7 summary count (no separate preview round-trip).
        $('#btn_rcpt_commit').prop('disabled', true);
        $('#rcpt_commit_result').html(skeleton(2));
        post({ action_type: 'wizard_commit_recipients', engagement_id: id, group_name: groupName, user_data: csv })
            .done(function (res) {
                if (res && res.result === 'success') {
                    WZ.user_group_id = res.user_group_id;
                    WZ.recipient_emails = (res.emails || [])
                        .map(function (e) { return String(e || '').toLowerCase(); })
                        .filter(Boolean);
                    $('#rcpt_commit_result').html(
                        '<div class="alert alert-success">' +
                        '<strong>Committed.</strong> Group <code>' + esc(res.group_name) + '</code> — ' +
                        '<strong>' + (res.committed || 0) + '</strong> recipient(s) stored, ' +
                        (res.skipped || 0) + ' skipped, ' +
                        (res.scope_violations || 0) + ' out of scope.' +
                        '</div>'
                    );
                    if (window.toastr) toastr.success('Recipients committed');
                    persistState();
                    unlockNext();
                } else {
                    $('#rcpt_commit_result').html('<div class="alert alert-danger">' + esc((res && res.error) || 'Commit failed') + '</div>');
                }
            })
            .fail(function () { $('#rcpt_commit_result').html('<div class="alert alert-danger">Request failed</div>'); })
            .always(function () { $('#btn_rcpt_commit').prop('disabled', false); });
    }

    // ----- Step 4: Landing + Tracker -------------------------------------

    function slugifyName(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 48);
    }

    function loadTrackers() {
        post({ action_type: 'wizard_list_web_trackers' })
            .done(function (res) {
                var $sel = $('#trk_select').empty();
                var trackers = (res && res.result === 'success') ? (res.trackers || []) : [];
                if (!trackers.length) {
                    $sel.append($('<option>').val('').text('— none yet — create one below —'));
                } else {
                    $sel.append($('<option>').val('').text('— select a tracker —'));
                    trackers.forEach(function (t) {
                        $sel.append($('<option>').val(t.tracker_id).text(
                            (t.tracker_name || t.tracker_id) + (t.active ? '' : ' (inactive)')
                        ));
                    });
                }
                if (WZ.tracker_id) { $sel.val(WZ.tracker_id); }
                // Auto-suggest a tracker name from the engagement slug.
                if (!$('#trk_new_name').val()) {
                    var slug = $('#tb_engagements tbody tr:first code').text() || $('#osint_domain').val() || 'tracker';
                    $('#trk_new_name').attr('placeholder', slugifyName(slug) + '-tracker');
                }
            });
    }

    function createTracker() {
        var name = ($('#trk_new_name').val() || '').trim();
        if (name === '') {
            var slug = $('#tb_engagements tbody tr:first code').text() || $('#osint_domain').val() || 'engagement';
            name = slugifyName(slug) + '-tracker';
        }
        var webhook = ($('#trk_webhook').val() || '').trim();
        $('#btn_trk_create').prop('disabled', true);
        post({ action_type: 'wizard_create_web_tracker', tracker_name: name, webhook_url: webhook })
            .done(function (res) {
                if (res && res.result === 'success') {
                    WZ.tracker_id = res.tracker_id;
                    WZ.tracker_mod_url = res.mod_url || '';
                    if (window.toastr) toastr.success('Tracker created');
                    loadTrackers();
                    $('#trk_select').val(res.tracker_id);
                    $('#trk_result').html('<div class="alert alert-success">Tracker <code>' + esc(res.tracker_id) + '</code> ready. Mod URL: <code>' + esc(res.mod_url || '') + '</code></div>');
                    persistState();
                } else {
                    $('#trk_result').html('<div class="alert alert-danger">' + esc((res && res.error) || 'Could not create tracker') + '</div>');
                }
            })
            .fail(function () { $('#trk_result').html('<div class="alert alert-danger">Request failed</div>'); })
            .always(function () { $('#btn_trk_create').prop('disabled', false); });
    }

    function selectedTrackerModUrl() {
        // Prefer a freshly-created tracker's mod_url; otherwise build from the
        // selected id (matches the server's <base>/mod?tlink=ID scheme).
        var id = $('#trk_select').val() || WZ.tracker_id;
        if (!id) return '';
        if (WZ.tracker_id === id && WZ.tracker_mod_url) return WZ.tracker_mod_url;
        return window.location.protocol + '//' + window.location.host + '/mod?tlink=' + encodeURIComponent(id);
    }

    function publicUrlFromSlug(slug) {
        return window.location.protocol + '//' + window.location.host + '/spear/sniperhost/cloned/' + slug + '/';
    }

    function onCloneSuccess(slug, publicUrl) {
        WZ.clone_slug = slug;
        WZ.landing_url = publicUrl || publicUrlFromSlug(slug);
        var id = $('#trk_select').val();
        if (id) { WZ.tracker_id = id; }
        $('#clone_result').html(
            '<div class="alert alert-success"><strong>Landing ready.</strong> ' +
            '<a href="' + esc(WZ.landing_url) + '" target="_blank" rel="noopener noreferrer">' + esc(WZ.landing_url) + '</a></div>'
        );
        if (window.toastr) toastr.success('Landing page ready');
        persistState();
        unlockNext();
    }

    function cloneSite() {
        var url = ($('#clone_url').val() || '').trim();
        var slug = slugifyName($('#clone_slug').val() || '');
        if (url === '') { if (window.toastr) toastr.warning('Enter a target URL'); return; }
        if (slug === '') { if (window.toastr) toastr.warning('Enter a slug'); return; }
        var trackerUrl = selectedTrackerModUrl();
        $('#btn_clone_site').prop('disabled', true);
        $('#clone_result').html(skeleton(2));
        $.ajax({
            url: 'sniperhost/manager/site_cloner_manager.php',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify({ action_type: 'clone_site', url: url, slug: slug, tracker_url: trackerUrl || null }),
            dataType: 'json'
        })
            .done(function (res) {
                if (res && res.result === 'success') {
                    onCloneSuccess(res.slug || slug, res.public_url);
                } else {
                    $('#clone_result').html('<div class="alert alert-danger">' + esc((res && res.error) || 'Clone failed') + '</div>');
                }
            })
            .fail(function (xhr) { $('#clone_result').html('<div class="alert alert-danger">Request failed (' + xhr.status + ')</div>'); })
            .always(function () { $('#btn_clone_site').prop('disabled', false); });
    }

    function loadLibraryOptions() {
        post({ action_type: 'wizard_list_landing_options' })
            .done(function (res) {
                if (!res || res.result !== 'success') return;
                var $sel = $('#lib_select').empty();
                var lib = res.library || [];
                if (!lib.length) {
                    $sel.append($('<option>').val('').text('— no library templates —'));
                    return;
                }
                $sel.append($('<option>').val('').text('— select a template —'));
                lib.forEach(function (l) {
                    $sel.append($('<option>').val(l.key).text(l.label + (l.has_2fa ? ' (+2FA)' : '')));
                });
            });
    }

    function cloneLibrary() {
        var source = $('#lib_select').val() || '';
        if (source === '') { if (window.toastr) toastr.warning('Pick a library template'); return; }
        var slug = slugifyName($('#clone_slug').val() || source);
        if (slug === '') slug = slugifyName(source);
        $('#btn_lib_clone').prop('disabled', true);
        $('#clone_result').html(skeleton(2));
        post({
            action_type: 'library_clone_to_my_sites',
            source_slug: source,
            dest_slug: slug,
            tracker_url: selectedTrackerModUrl(),
            force: false
        })
            .done(function (res) {
                if (res && res.result === 'success') {
                    onCloneSuccess(res.slug || slug, res.public_url);
                } else {
                    $('#clone_result').html('<div class="alert alert-danger">' + esc((res && res.error) || 'Clone failed') + '</div>');
                }
            })
            .fail(function () { $('#clone_result').html('<div class="alert alert-danger">Request failed</div>'); })
            .always(function () { $('#btn_lib_clone').prop('disabled', false); });
    }

    function loadStep4() {
        loadTrackers();
        loadLibraryOptions();
    }

    // ----- Step 5: Mail template (inline editor + auto-wire) -------------

    var summernoteReady = false;

    function ensureSummernote() {
        if (summernoteReady) return;
        $('#mt_summernote').summernote({
            height: 320,
            lang: 'en-UK',
            disableDragAndDrop: true,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'clear']],
                ['fontname', ['fontname', 'fontsize', 'color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link']],
                ['view', ['codeview']]
            ],
            codemirror: { mode: 'text/html', htmlMode: true, lineNumbers: true, lineWrapping: true }
        });
        summernoteReady = true;
    }

    function loadMailPretexts() {
        ensureSummernote();
        post({ action_type: 'list_pretexts_ranked', categories: [], limit: 8 })
            .done(function (res) {
                var $g = $('#mt_pretexts').empty();
                if (!res || res.result !== 'success' || !(res.pretexts || []).length) {
                    $g.html('<div class="small text-muted">No pretexts in the library yet.</div>');
                    return;
                }
                res.pretexts.forEach(function (p) {
                    var $btn = $('<button class="btn btn-sm btn-outline-info btn-block text-left mb-2"></button>');
                    $btn.append($('<strong></strong>').text(p.name || ''));
                    $btn.append($('<div class="small text-muted"></div>').text(p.subject || ''));
                    $btn.on('click', function () { applyPretext(p.id); });
                    $g.append($btn);
                });
            })
            .fail(function () { $('#mt_pretexts').html('<div class="small text-danger">Could not load pretexts.</div>'); });
    }

    function applyPretext(pretextId) {
        // Clone the pretext into a real editable template, then load its content
        // into the inline editor; the operator wires + saves from here.
        post({ action_type: 'clone_pretext_to_my_templates', pretext_id: pretextId })
            .done(function (res) {
                if (!res || res.result !== 'success') {
                    if (window.toastr) toastr.error((res && res.error) || 'Could not load pretext');
                    return;
                }
                WZ.mail_template_id = res.mail_template_id || '';
                post({ action_type: 'get_mail_template_from_template_id', mail_template_id: res.mail_template_id })
                    .done(function (t) {
                        ensureSummernote();
                        $('#mt_name').val(t.mail_template_name || '');
                        $('#mt_subject').val(t.mail_template_subject || '');
                        $('#mt_summernote').summernote('code', t.mail_template_content || '');
                        if (window.toastr) toastr.success('Pretext loaded — edit & wire');
                    });
            })
            .fail(function () { if (window.toastr) toastr.error('Could not load pretext'); });
    }

    function startBlank() {
        ensureSummernote();
        WZ.mail_template_id = '';
        $('#mt_summernote').summernote('code', '<p>Hi {{FNAME}},</p><p></p><p>Please review and confirm your account.</p>');
        $('#mt_summernote').summernote('focus');
    }

    // Wire the CTA + open pixel into the body:
    //  1. Replace the pretext-library placeholder marker (the seed templates
    //     ship `https://example.com/REPLACE-WITH-LANDING-URL`) with the real
    //     cloned-landing URL — otherwise the pre-flight mail_body gate refuses
    //     to launch ("CTA still points to the REPLACE-WITH-LANDING-URL marker").
    //  2. Append a fresh CTA only if the landing URL still isn't present.
    //  3. Append the {{TRACKER}} open-pixel placeholder if absent.
    function wireBody(html) {
        var landing = WZ.landing_url || '';
        var ctaHref = landing ? (landing + (landing.indexOf('?') === -1 ? '?' : '&') + 'rid={{RID}}') : '';
        if (ctaHref) {
            html = html
                .replace(/https?:\/\/example\.com\/REPLACE-WITH-LANDING-URL/gi, ctaHref)
                .replace(/REPLACE-WITH-LANDING-URL/gi, ctaHref);
            if (html.indexOf(landing) === -1) {
                html += '<p><a href="' + ctaHref + '">' + ctaHref + '</a></p>';
            }
        }
        if (html.indexOf('{{TRACKER}}') === -1) {
            html += '{{TRACKER}}';
        }
        return html;
    }

    function saveMailTemplate() {
        ensureSummernote();
        var name = ($('#mt_name').val() || '').trim();
        var subject = ($('#mt_subject').val() || '').trim();
        if (name === '') { if (window.toastr) toastr.warning('Enter a template name'); $('#mt_name').focus(); return; }
        if (!WZ.landing_url) {
            if (window.toastr) toastr.warning('Finish the landing page step first — the mail body needs a landing URL to wire the CTA.');
            return;
        }
        var body = wireBody($('#mt_summernote').summernote('code'));
        $('#mt_summernote').summernote('code', body);
        WZ.rendered_body = body;
        var tplId = WZ.mail_template_id || getRandomId();
        WZ.mail_template_id = tplId;
        $('#btn_mt_save').prop('disabled', true);
        $('#mt_result').html(skeleton(2));
        post({
            action_type: 'save_mail_template',
            mail_template_id: tplId,
            mail_template_name: name,
            mail_template_subject: subject,
            mail_template_content: body,
            timage_type: 1,            // {{TRACKER}} default open-pixel present
            attachments: [],
            mail_content_type: 'text/html'
        })
            .done(function (res) {
                if (res && res.result === 'success') {
                    $('#mt_result').html('<div class="alert alert-success"><strong>Saved &amp; wired.</strong> CTA + tracking pixel injected.</div>');
                    $('#mt_preview').html(
                        '<div class="card"><div class="card-header small text-muted">Wired body preview</div>' +
                        '<div class="card-body">' + body + '</div></div>'
                    );
                    if (window.toastr) toastr.success('Mail template saved');
                    persistState();
                    unlockNext();
                } else {
                    $('#mt_result').html('<div class="alert alert-danger">' + esc((res && res.error) || 'Save failed') + '</div>');
                }
            })
            .fail(function () { $('#mt_result').html('<div class="alert alert-danger">Request failed</div>'); })
            .always(function () { $('#btn_mt_save').prop('disabled', false); });
    }

    // ----- Step 6: Sender (select or inline create) ----------------------

    var g_sender_list = [];

    function loadSenders() {
        post({ action_type: 'get_sender_list' })
            .done(function (data) {
                var $sel = $('#snd_select').empty();
                g_sender_list = Array.isArray(data) ? data : [];
                if (!g_sender_list.length || (data && data.error)) {
                    $sel.append($('<option>').val('').text('— none yet — create one below —'));
                    return;
                }
                $sel.append($('<option>').val('').text('— select a sender —'));
                g_sender_list.forEach(function (s) {
                    $sel.append($('<option>').val(s.sender_list_id).text(
                        (s.sender_name || s.sender_list_id) + ' — ' + (s.sender_from || '')
                    ));
                });
                if (WZ.sender_list_id) { $sel.val(WZ.sender_list_id); }
            });
    }

    function senderById(id) {
        for (var i = 0; i < g_sender_list.length; i++) {
            if (String(g_sender_list[i].sender_list_id) === String(id)) return g_sender_list[i];
        }
        return null;
    }

    function fromDomain(from) {
        var at = String(from || '').indexOf('@');
        return at === -1 ? '' : String(from).slice(at + 1).trim().toLowerCase();
    }

    function useSelectedSender() {
        var id = $('#snd_select').val();
        if (!id) { if (window.toastr) toastr.warning('Pick a sender'); return; }
        var s = senderById(id);
        WZ.sender_list_id = id;
        WZ.sender_from = s ? (s.sender_from || '') : '';
        $('#snd_result').html('<div class="alert alert-success">Sender <code>' + esc(s ? (s.sender_name || id) : id) + '</code> selected.</div>');
        if (window.toastr) toastr.success('Sender selected');
        persistState();
        unlockNext();
    }

    function saveSender() {
        var name = ($('#snd_name').val() || '').trim();
        var smtp = ($('#snd_smtp').val() || '').trim();
        var from = ($('#snd_from').val() || '').trim();
        var user = ($('#snd_user').val() || '').trim();
        var pwd  = $('#snd_pwd').val() || '';
        var mailbox = ($('#snd_mailbox').val() || '').trim();
        if (name === '' || smtp === '' || from === '' || user === '') {
            if (window.toastr) toastr.warning('Name, SMTP server, From and Username are required');
            return;
        }
        var autoMailbox = mailbox === '' ? 1 : 0;
        var sid = getRandomId();
        $('#btn_snd_save').prop('disabled', true);
        post({
            action_type: 'save_sender_list',
            sender_list_id: sid,
            sender_list_mail_sender_name: name,
            sender_list_mail_sender_SMTP_server: smtp,
            sender_list_mail_sender_from: from,
            sender_list_mail_sender_acc_username: user,
            sender_list_mail_sender_acc_pwd: pwd,
            mail_sender_mailbox: mailbox,
            cb_auto_mailbox: autoMailbox,
            sender_list_cust_headers: {},
            dsn_type: 'custom'
        })
            .done(function (res) {
                if (res && res.result === 'success') {
                    WZ.sender_list_id = sid;
                    WZ.sender_from = from;
                    if (window.toastr) toastr.success('Sender saved & selected');
                    $('#snd_result').html('<div class="alert alert-success">Sender <code>' + esc(name) + '</code> saved and selected.</div>');
                    loadSenders();
                    persistState();
                    unlockNext();
                } else {
                    $('#snd_result').html('<div class="alert alert-danger">' + esc((res && res.error) || 'Save failed') + '</div>');
                }
            })
            .fail(function () { $('#snd_result').html('<div class="alert alert-danger">Request failed</div>'); })
            .always(function () { $('#btn_snd_save').prop('disabled', false); });
    }

    // ----- Step 7: Pre-flight + Launch -----------------------------------

    function buildLaunchSummary() {
        var rows = [
            ['Recipient group', WZ.user_group_id ? (WZ.recipient_emails.length + ' recipient(s)') : '—'],
            ['Landing URL', WZ.landing_url || '—'],
            ['Web tracker', WZ.tracker_id || '—'],
            ['Mail template', WZ.mail_template_id || '—'],
            ['Sender', WZ.sender_from ? (WZ.sender_from + ' (' + WZ.sender_list_id + ')') : (WZ.sender_list_id || '—')]
        ];
        var html = '<table class="table table-sm"><tbody>';
        rows.forEach(function (r) {
            html += '<tr><th class="small text-muted" style="width:30%">' + esc(r[0]) + '</th><td class="small">' + esc(r[1]) + '</td></tr>';
        });
        html += '</tbody></table>';
        // Default the target domain from the OSINT field if blank.
        if (!$('#pf_target_domain').val() && $('#osint_domain').val()) {
            $('#pf_target_domain').val(($('#osint_domain').val() || '').trim().toLowerCase());
        }
        $('#launch_summary').html(html);
    }

    function gatherPreflightContext() {
        var scope = ($('#eng_scope').val() || '').split(/[\s,;]+/).map(function (e) { return e.trim().toLowerCase(); }).filter(Boolean);
        return {
            recipient_emails:    WZ.recipient_emails,
            scope_allowlist:     scope,
            sender_domain:       fromDomain(WZ.sender_from),
            target_domain:       ($('#pf_target_domain').val() || '').trim().toLowerCase(),
            target_dmarc_policy: $('#pf_dmarc').val(),
            webhook_url:         '',
            landing_url:         WZ.landing_url || '',
            rendered_mail_body:  WZ.rendered_body || ''
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
        post({ action_type: 'wizard_preflight', engagement_id: engId(), context: ctx })
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
        var id = engId();
        if (!id) { if (window.toastr) toastr.warning('Save the engagement first'); return; }
        if (!WZ.user_group_id || !WZ.mail_template_id || !WZ.sender_list_id) {
            if (window.toastr) toastr.error('Complete recipients, mail and sender first');
            return;
        }
        var ctx = gatherPreflightContext();
        $('#btn_launch').prop('disabled', true);
        post({
            action_type:      'wizard_launch_campaign',
            engagement_id:    id,
            user_group_id:    WZ.user_group_id,
            mail_template_id: WZ.mail_template_id,
            sender_list_id:   WZ.sender_list_id,
            tracker_id:       WZ.tracker_id || '',
            landing_url:      WZ.landing_url || '',
            campaign_name:    'Quick-Start ' + new Date().toISOString().slice(0, 16),
            scheduled_time:   '',
            camp_status:      0,
            context:          ctx
        })
            .done(function (res) {
                if (res && res.result === 'success') {
                    if (window.toastr) toastr.success('Launched. Campaign ' + res.campaign_id);
                    $(document).trigger('wizard:launched');
                    window.location.href = 'EngagementView?engagement_id=' + res.engagement_id;
                } else {
                    if (window.toastr) toastr.error((res && res.error) || 'Launch rejected');
                    $('#btn_launch').prop('disabled', false);
                }
            })
            .fail(function () { if (window.toastr) toastr.error('Request failed'); $('#btn_launch').prop('disabled', false); });
    }

    // ----- Bridges to the stepflow controller ----------------------------
    // The controller (wizard_stepflow.js) owns navigation + persistence. These
    // thin helpers let the commit handlers above push fresh IDs into the
    // persisted state and unlock the Next button after a successful commit.
    function persistState() {
        if (window.TAPhishWizard && typeof window.TAPhishWizard.persistNow === 'function') {
            window.TAPhishWizard.persistNow();
        }
    }
    function unlockNext() {
        if (window.TAPhishWizard && typeof window.TAPhishWizard.unlockNext === 'function') {
            window.TAPhishWizard.unlockNext();
        }
    }

    // Phase 3.57: surface the per-step entry points + the live state object the
    // stepflow controller drives. Navigation/persistence live there; this file
    // owns what each step DOES and exposes WZ so the controller can serialise it.
    window.TAPhishWizard = {
        post:                post,
        state:               WZ,
        setStepperState:     setStepperState,
        runOsint:            runOsint,
        runDkimGen:          runDkimGen,
        runRecipientPreview: runRecipientPreview,
        runRecipientCommit:  runRecipientCommit,
        loadStep4:           loadStep4,
        loadMailPretexts:    loadMailPretexts,
        loadSenders:         loadSenders,
        buildLaunchSummary:  buildLaunchSummary,
        runPreflight:        runPreflight,
        runLaunch:           runLaunch,
        refreshList:         refreshList
    };

    $(function () {
        $('#frm_engagement').on('submit', onSubmit);
        $('#btn_refresh_eng').on('click', refreshList);
        // A/B: sensible window defaults + live domain-chip preview.
        prefillWindow();
        $('#eng_scope').on('input', renderScopeChips);
        renderScopeChips();
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
        // Step 3 — Recipients.
        $('#btn_rcpt_preview').on('click', runRecipientPreview);
        $('#btn_rcpt_commit').on('click', runRecipientCommit);
        // Step 4 — Landing + Tracker.
        $('#btn_trk_create').on('click', createTracker);
        $('#btn_clone_site').on('click', cloneSite);
        $('#btn_lib_clone').on('click', cloneLibrary);
        // Step 5 — Mail template.
        $('#btn_mt_blank').on('click', startBlank);
        $('#btn_mt_save').on('click', saveMailTemplate);
        // Step 6 — Sender (+ advanced DKIM).
        $('#btn_snd_use').on('click', useSelectedSender);
        $('#btn_snd_save').on('click', saveSender);
        $('#btn_snd_toggle').on('click', function () { $('#snd_create').slideToggle(); });
        $('#btn_gen_dkim').on('click', runDkimGen);
        // Step 7 — Pre-flight + Launch.
        $('#btn_run_preflight').on('click', runPreflight);
        $('#btn_launch').on('click', runLaunch);
        refreshList();
    });
})();
