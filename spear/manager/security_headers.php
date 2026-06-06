<?php
/**
 * Defense-in-depth response headers for the admin panel.
 *
 * Loaded from session_manager.php so every authenticated page + JSON
 * dispatcher gets them. Not applied to the public tracking endpoints
 * (track.php, qt.php, mod.php) — those are explicitly meant to be
 * embedded on third-party landing pages, so X-Frame-Options: DENY
 * would break the tracking pixel.
 *
 * Content-Security-Policy is intentionally NOT emitted here yet.
 * The dashboard uses inline event handlers (onclick="…", inline
 * <script> blocks emitted by z_menu.php for CSRF / force-pwd-change),
 * which a meaningful CSP would block. A proper CSP rollout means
 * moving every inline handler to an addEventListener equivalent
 * first — multi-day audit, not a side effect of this slice.
 */

if (!function_exists('taphish_security_headers_list')) {
    /**
     * The defense-in-depth header set as an ordered list of "Name: value"
     * strings. Pure (no header() side effect) so it can be unit-tested and
     * reused; taphish_emit_security_headers() emits each line. Notes on intent:
     *
     *  - X-Content-Type-Options: nosniff — keep browsers honest about
     *    Content-Type, so a JSON dispatcher response can't be sniffed into a
     *    script.
     *  - X-Frame-Options: DENY — clickjacking protection. The admin panel is
     *    never framed; the dashboard-share read-only view uses an in-page
     *    tk_id token, not an iframe.
     *  - Referrer-Policy — don't leak the panel URL (campaign ids in the query
     *    string) to third parties via Referer on outgoing links.
     *  - Strict-Transport-Security — one-year HSTS; only meaningful on HTTPS,
     *    harmless on plain HTTP (the browser ignores it).
     *  - Permissions-Policy — strip high-risk browser APIs the dashboard never
     *    needs (easier to grant later than to claw back from a compromised page).
     *
     * Content-Security-Policy is intentionally NOT in this set yet — see the
     * file header for why (inline handlers must move to addEventListener first).
     *
     * @return string[]
     */
    function taphish_security_headers_list(): array
    {
        return [
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'Strict-Transport-Security: max-age=31536000; includeSubDomains',
            'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        ];
    }
}

if (!function_exists('taphish_emit_security_headers')) {
    function taphish_emit_security_headers(): void
    {
        // No-op if anything has already started writing the response — leaves
        // the existing header set untouched rather than emitting a warning.
        if (headers_sent()) {
            return;
        }
        foreach (taphish_security_headers_list() as $h) {
            header($h);
        }
    }
}
