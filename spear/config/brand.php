<?php
/**
 * Brand abstraction layer for TAPhish.
 *
 * All product-name, company-name, taglines, logo paths, and brand colors
 * are centralized here. Change the constants below to rebrand the product
 * without touching the rest of the codebase.
 *
 * Drop replacement logo PNG/SVG files into spear/images/brand/ — keep the
 * same filenames (logo-icon.png, logo-icon2x.png, logo.png, logo-text.png,
 * favicon.png) and they will be picked up automatically.
 */

if (!defined('BRAND_PRODUCT_NAME')) {
    define('BRAND_PRODUCT_NAME',  'TAPhish');
    define('BRAND_COMPANY',       't-alpha GmbH');
    define('BRAND_TAGLINE',       'Web-Email Spear Phishing Toolkit');
    define('BRAND_PRODUCT_VERSION', '2.1');
    define('BRAND_COPYRIGHT_YEAR',  '2026');

    // Sampled from the official t-alpha logo (Phase 3.33 rebrand).
    define('BRAND_PRIMARY_COLOR',   '#0071bb');
    define('BRAND_ACCENT_COLOR',    '#005a96');

    define('BRAND_IMG_DIR',         'images/brand');
    define('BRAND_LOGO_ICON',       'images/brand/logo-icon.png');
    define('BRAND_LOGO_ICON_2X',    'images/brand/logo-icon2x.png');
    define('BRAND_LOGO_TEXT',       'images/brand/logo-text.png');
    define('BRAND_LOGO_FULL',       'images/brand/logo.png');
    define('BRAND_FAVICON',         'images/brand/favicon.png');
}

function brand_title()
{
    return BRAND_PRODUCT_NAME . ' - ' . BRAND_TAGLINE;
}

function brand_copyright()
{
    return '&copy; ' . BRAND_COPYRIGHT_YEAR . ' ' . BRAND_COMPANY . ' | All Rights Reserved';
}

function brand_product_version()
{
    return BRAND_PRODUCT_VERSION;
}
