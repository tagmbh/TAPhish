<?php
/**
 * Brand abstraction layer for TAPhish.
 *
 * All product-name, company-name, taglines, logo paths, and brand colors
 * are centralized here. Change the constants below to rebrand the product
 * without touching the rest of the codebase.
 *
 * Drop replacement logo files into spear/images/brand/ — keep the same
 * filenames (logo-icon.svg, logo-text.svg, logo-text-white.svg, logo.svg,
 * favicon.png) and they will be picked up automatically. SVG is preferred
 * for crisp rendering at any size; the favicon stays PNG for browser-old compat.
 *
 * Dark "dim-panel" chrome (sidebar, login, change-password, about) uses the
 * white wordmark (logo-text-white.svg); the blue wordmark (logo-text.svg) and
 * the framed primary (logo.svg) are reserved for light/print surfaces.
 */

if (!defined('BRAND_PRODUCT_NAME')) {
    define('BRAND_PRODUCT_NAME',  'TAPhish');
    define('BRAND_COMPANY',       'T-Alpha GmbH');
    define('BRAND_COMPANY_URL',   'https://www.t-alpha.ch');
    define('BRAND_TAGLINE',       'Web-Email Spear Phishing Toolkit');
    define('BRAND_PRODUCT_VERSION', '2.1');
    define('BRAND_COPYRIGHT_YEAR',  '2026');

    // Sampled from the official t-alpha logo (Phase 3.33 rebrand).
    define('BRAND_PRIMARY_COLOR',   '#0071bb');
    define('BRAND_ACCENT_COLOR',    '#005a96');

    define('BRAND_IMG_DIR',         'images/brand');
    define('BRAND_LOGO_ICON',       'images/brand/logo-icon.svg');
    define('BRAND_LOGO_ICON_2X',    'images/brand/logo-icon.svg');
    define('BRAND_LOGO_TEXT',       'images/brand/logo-text.svg');
    define('BRAND_LOGO_TEXT_WHITE', 'images/brand/logo-text-white.svg');
    define('BRAND_LOGO_FULL',       'images/brand/logo.svg');
    define('BRAND_LOGO_MONO',       'images/brand/logo-mono.svg');
    define('BRAND_FAVICON',         'images/brand/favicon.png');
}

function brand_title()
{
    return BRAND_PRODUCT_NAME . ' - ' . BRAND_TAGLINE;
}

function brand_copyright()
{
    return '&copy; ' . BRAND_COPYRIGHT_YEAR . ' '
        . '<a href="' . BRAND_COMPANY_URL . '" target="_blank" rel="noopener noreferrer">'
        . BRAND_COMPANY . '</a>'
        . ' &middot; ' . BRAND_PRODUCT_NAME . ' v' . BRAND_PRODUCT_VERSION;
}

function brand_product_version()
{
    return BRAND_PRODUCT_VERSION;
}
