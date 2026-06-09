/**
 * Phase 3.57 — Quick-Start wizard: pure, DOM-free helpers.
 *
 * These functions have no jQuery / DOM / network dependency so they can be
 * unit-tested in node (tests/js/wizardPure.test.mjs) and reused by
 * quick_start.js in the browser. Loaded as a plain <script> before
 * quick_start.js; also exported via module.exports for the node test.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    root.TAPhishWizardPure = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Wire the CTA + open pixel into a mail body. Pure: the landing URL is a
    // parameter (the browser caller passes WZ.landing_url).
    //  1. Replace the pretext-library placeholder marker (seed templates ship
    //     `https://example.com/REPLACE-WITH-LANDING-URL`) with the real cloned-
    //     landing URL — otherwise the pre-flight mail_body gate refuses to
    //     launch ("CTA still points to the REPLACE-WITH-LANDING-URL marker").
    //  2. Append a fresh CTA only if the landing URL still isn't present.
    //  3. Append the {{TRACKER}} open-pixel placeholder if absent.
    // Idempotent: running it again on its own output is a no-op.
    function wireBody(html, landingUrl) {
        html = html == null ? '' : String(html);
        var landing = landingUrl || '';
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

    // Normalise an operator label into a URL/filesystem-safe slug.
    function slugifyName(s) {
        return String(s == null ? '' : s)
            .toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 48);
    }

    return {
        wireBody: wireBody,
        slugifyName: slugifyName
    };
});
