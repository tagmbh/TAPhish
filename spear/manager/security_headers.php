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

if (!function_exists('taphish_emit_security_headers')) {
    function taphish_emit_security_headers(): void
    {
        // No-op if anything has already started writing the response — leaves
        // the existing header set untouched rather than emitting a warning.
        if (headers_sent()) {
            return;
        }
        // MIME-sniffing protection — keep browsers honest about
        // Content-Type, so a JSON dispatcher response can't be coerced
        // into being executed as a script via a sniff.
        header('X-Content-Type-Options: nosniff');

        // Clickjacking protection. The admin panel is never embedded
        // by itself in an iframe; the dashboard-share read-only view
        // uses an in-page tk_id token, not an iframe.
        header('X-Frame-Options: DENY');

        // Don't leak the panel URL (which can include campaign ids in
        // query strings) to third parties via Referer when the operator
        // clicks an outgoing link.
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // HSTS — only meaningful on HTTPS, but emitting it doesn't hurt
        // on plain HTTP since the browser ignores it. One-year max-age
        // matches common production hardening guides.
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

        // Permissions-Policy: strip access to high-risk browser APIs
        // the dashboard never needs. Easier to grant later than to
        // claw back from a compromised page.
        header(
            'Permissions-Policy: '
            . 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'
        );
    }
}
