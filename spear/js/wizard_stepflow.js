/* Phase 3.56: QuickStart wizard — resumable step-by-step controller.
 *
 * Loaded AFTER quick_start.js so it can drive the per-step "run" functions
 * exposed on window.TAPhishWizard. This file owns NAVIGATION and PERSISTENCE
 * only — it never changes what each step does, just which one is visible and
 * whether progress is saved so the engagement is resumable.
 *
 * Contract (window.TAPhishWizard, set up by quick_start.js):
 *   post(payload)        -> jQuery AJAX promise to the dispatcher
 *   setStepperState(n)   -> highlight step n in #t_stepper
 *   loadLandingOptions() -> populate Step 6 (lazy; only when shown)
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
    function collectState() {
        return {
            target_domain: ($('#osint_domain').val() || '').trim(),
            dkim_selector: ($('#dkim_selector').val() || '').trim(),
            landing_slug:  '',
            pretext_id:    0
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

    function updateNav() {
        var n = state.step;
        $('#wiz_back').prop('disabled', n <= 1);
        var $next = $('#wiz_next');
        if (n >= TOTAL) {
            $next.hide();
        } else {
            $next.show();
            // Advancing past Step 1 needs a saved engagement (we need its id
            // to persist progress and to launch).
            var blocked = (n === 1 && !engId());
            $next.prop('disabled', blocked)
                 .attr('title', blocked ? 'Save the engagement first' : '');
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
        if (n === 6 && W.loadLandingOptions) { W.loadLandingOptions(); }
        if (!opts || !opts.noScroll) {
            try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (_) { window.scrollTo(0, 0); }
        }
    }

    function next() {
        if (state.step >= TOTAL) { return; }
        if (state.step === 1 && !engId()) {
            if (window.toastr) { toastr.warning('Save the engagement first'); }
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
        // pretext_id / landing_slug are informational only — the picked
        // artifacts live in their own tables; nothing to re-render here.
    }

    function restore() {
        var step = parseInt($('#wizard_resume_step').val(), 10) || 1;
        applyResumeState($('#wizard_resume_state').val() || '');
        // First paint: jump straight to the resumed step with no scroll jank.
        showStep(step > 1 ? step : 1, { noScroll: true });
    }

    $(function () {
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
