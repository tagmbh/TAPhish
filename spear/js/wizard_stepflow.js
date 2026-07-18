/* Phase 3.56: QuickStart wizard — resumable step-by-step controller.
 *
 * Loaded AFTER quick_start.js so it can drive the per-step "run" functions
 * exposed on window.TAPhishWizard. This file owns NAVIGATION and PERSISTENCE
 * only — it never changes what each step does, just which one is visible and
 * whether progress is saved so the engagement is resumable.
 *
 * Contract (window.TAPhishWizard, set up by quick_start.js):
 *   post(payload)        -> jQuery AJAX promise to the dispatcher
 *   state                -> live object holding the committed full-funnel IDs
 *   setStepperState(n)   -> highlight step n in #t_stepper
 *   loadStep4()          -> populate Step 4 tracker + library lists (lazy)
 *   loadMailPretexts()   -> populate Step 5 pretext starters (lazy)
 *   loadSenders()        -> populate Step 6 sender dropdown (lazy)
 *   buildLaunchSummary() -> render the Step 7 read-only summary (lazy)
 *   runLaunch()          -> Step 7 launch (redirects on success)
 */

(function () {
    'use strict';

    var TOTAL = 7;
    var W = window.TAPhishWizard || {};
    var state = { step: 1 };

    function engId() {
        return parseInt($('#wizard_engagement_id').val(), 10) || 0;
    }

    // The whitelisted, non-secret inputs we persist so a reopened wizard can
    // restore the operator's place. Mirrors taphish_wizard_state_normalize.
    // The full-funnel IDs live on W.state (window.TAPhishWizard.state), set by
    // quick_start.js as each step commits.
    function collectState() {
        var s = (W && W.state) ? W.state : {};
        return {
            target_domain:    ($('#osint_domain').val() || '').trim(),
            dkim_selector:    ($('#dkim_selector').val() || '').trim(),
            landing_slug:     s.clone_slug || '',
            pretext_id:       0,
            user_group_id:    s.user_group_id    || '',
            mail_template_id: s.mail_template_id || '',
            sender_list_id:   s.sender_list_id   || '',
            tracker_id:       s.tracker_id       || '',
            clone_slug:       s.clone_slug        || '',
            landing_url:      s.landing_url       || '',
            campaign_type:    'mail_landing'
        };
    }

    function persist(step) {
        var id = engId();
        if (!id || !W.post) { return; }
        W.post({
            action_type:   'wizard_save_progress',
            engagement_id: id,
            step:          step,
            state:         collectState()
        });
    }

    // Gating: each commit-step's Next stays disabled until that step's artifact
    // exists on W.state. Step 7 has no Next (Launch is terminal).
    function stepBlocked(n) {
        var s = (W && W.state) ? W.state : {};
        if (n === 1) { return !engId(); }
        if (n === 3) { return !s.user_group_id; }
        if (n === 4) { return !s.landing_url; }
        if (n === 5) { return !s.mail_template_id; }
        if (n === 6) { return !s.sender_list_id; }
        return false; // steps 2 are free-advance
    }

    function blockedMsg(n) {
        return ({
            1: 'Save the engagement first',
            3: 'Commit recipients first',
            4: 'Clone a landing page first',
            5: 'Save & wire the mail template first',
            6: 'Select a sender first'
        })[n] || '';
    }

    function updateNav() {
        var n = state.step;
        $('#wiz_back').prop('disabled', n <= 1);
        var $next = $('#wiz_next');
        if (n >= TOTAL) {
            $next.hide();
        } else {
            $next.show();
            var blocked = stepBlocked(n);
            $next.prop('disabled', blocked)
                 .attr('title', blocked ? blockedMsg(n) : '');
        }
        $('#wiz_step_label').text('Step ' + n + ' of ' + TOTAL);
    }

    function showStep(n, opts) {
        n = Math.max(1, Math.min(TOTAL, n));
        state.step = n;
        $('.step-wrap').hide();
        $('#step' + n + '_wrap').show();
        if (W.setStepperState) { W.setStepperState(n); }
        updateNav();
        // Lazy data loads for steps that fetch on first view.
        if (n === 4 && W.loadStep4)          { W.loadStep4(); }
        if (n === 5 && W.loadMailPretexts)   { W.loadMailPretexts(); }
        if (n === 6 && W.loadSenders)        { W.loadSenders(); }
        if (n === 7 && W.buildLaunchSummary) { W.buildLaunchSummary(); }
        if (!opts || !opts.noScroll) {
            try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (_) { window.scrollTo(0, 0); }
        }
    }

    function next() {
        if (state.step >= TOTAL) { return; }
        if (stepBlocked(state.step)) {
            if (window.toastr) { toastr.warning(blockedMsg(state.step) || 'Finish this step first'); }
            return;
        }
        var target = state.step + 1;
        persist(target);
        showStep(target);
    }

    function back() {
        if (state.step > 1) { showStep(state.step - 1); }
    }

    function applyResumeState(raw) {
        if (!raw) { return; }
        var st;
        try { st = JSON.parse(raw); } catch (_) { return; }
        if (!st || typeof st !== 'object') { return; }
        if (st.target_domain && !$('#osint_domain').val()) { $('#osint_domain').val(st.target_domain); }
        if (st.dkim_selector) { $('#dkim_selector').val(st.dkim_selector); }
        // Restore the committed full-funnel IDs onto W.state so gating + launch
        // pick up where the operator left off. The artifacts themselves live in
        // their own tables; we only carry the references.
        if (W && W.state) {
            if (st.user_group_id)    { W.state.user_group_id    = st.user_group_id; }
            if (st.mail_template_id) { W.state.mail_template_id = st.mail_template_id; }
            if (st.sender_list_id)   { W.state.sender_list_id   = st.sender_list_id; }
            if (st.tracker_id)       { W.state.tracker_id       = st.tracker_id; }
            if (st.clone_slug)       { W.state.clone_slug       = st.clone_slug; }
            if (st.landing_url)      { W.state.landing_url      = st.landing_url; }
        }
    }

    // Inverse of quick_start.js localInputToUtc: a stored UTC datetime (either
    // "YYYY-MM-DD HH:MM:SS" from the DB or "YYYY-MM-DDTHH:MM") -> the local
    // wall-clock "YYYY-MM-DDTHH:MM" the native datetime-local picker expects.
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function utcToLocalInput(v) {
        if (!v) { return ''; }
        var s = String(v).trim().replace(' ', 'T');
        if (!/[zZ]|[+-]\d\d:?\d\d$/.test(s)) { s += 'Z'; } // force UTC interpretation
        var d = new Date(s);
        if (isNaN(d.getTime())) { return ''; }
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
            + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    // Fix (blank-Step-1 bug): pre-fill the Step 1 metadata form from the saved
    // engagement when resuming a draft. Text fields fill directly; the window
    // dates convert UTC->local (overwriting quick_start.js's now/+14d default,
    // which has already run since that script loads first).
    function applyStep1Meta() {
        if (!engId()) { return; }                       // only when resuming a saved draft
        var m;
        try { m = JSON.parse($('#wizard_resume_meta').val() || ''); } catch (_) { return; }
        if (!m || typeof m !== 'object') { return; }
        if (m.name)       { $('#eng_name').val(m.name); }
        if (m.target_org) { $('#eng_org').val(m.target_org); }
        if (m.notes)      { $('#eng_notes').val(m.notes); }
        if (m.scope)      { $('#eng_scope').val(m.scope).trigger('input'); } // re-render chips
        var s = utcToLocalInput(m.start_at); if (s) { $('#eng_start').val(s); }
        var e = utcToLocalInput(m.end_at);   if (e) { $('#eng_end').val(e); }
    }

    function restore() {
        var step = parseInt($('#wizard_resume_step').val(), 10) || 1;
        applyStep1Meta();
        applyResumeState($('#wizard_resume_state').val() || '');
        // First paint: jump straight to the resumed step with no scroll jank.
        showStep(step > 1 ? step : 1, { noScroll: true });
    }

    $(function () {
        // Augment the API quick_start.js published so its commit handlers can
        // persist fresh state + unlock Next without owning navigation.
        if (window.TAPhishWizard) {
            window.TAPhishWizard.persistNow = function () { persist(state.step); };
            window.TAPhishWizard.unlockNext = function () { updateNav(); };
        }

        $('#wiz_next').on('click', next);
        $('#wiz_back').on('click', back);

        // Stepper is a shortcut: jump to any step once the engagement exists
        // (every step after 1 is non-blocking); Step 1 is always reachable.
        $('#t_stepper > li').css('cursor', 'pointer').on('click', function () {
            var s = parseInt($(this).attr('data-step'), 10) || 1;
            if (s === 1 || engId()) {
                if (s > state.step) { persist(s); }
                showStep(s);
            } else if (window.toastr) {
                toastr.info('Save the engagement (Step 1) first');
            }
        });

        // quick_start.js fires this once the engagement row is saved: capture
        // the id, then advance into the guided flow at Step 2.
        $(document).on('wizard:saved', function (_e, id) {
            if (id) { $('#wizard_engagement_id').val(id); }
            persist(2);
            showStep(2);
        });

        // Mark Step 7 complete on a successful launch so the "Continue setup"
        // entry points drop away (status also leaves draft, which alone hides
        // them — this is belt-and-suspenders).
        $(document).on('wizard:launched', function () { persist(7); });

        restore();
    });
})();
