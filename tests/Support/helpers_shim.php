<?php

/**
 * Loads pure helpers under test without dragging in DB/session machinery.
 */

require_once __DIR__ . '/../../spear/manager/filters.php';
require_once __DIR__ . '/../../spear/manager/site_cloner_filters.php';
require_once __DIR__ . '/../../spear/manager/csrf.php';
require_once __DIR__ . '/../../spear/manager/password_hash_helper.php';
require_once __DIR__ . '/../../spear/manager/mail_presets.php';
require_once __DIR__ . '/../../spear/manager/campaign_completion.php';
require_once __DIR__ . '/../../spear/manager/osint_hunter.php';
require_once __DIR__ . '/../../spear/manager/osint_crt_sh.php';
require_once __DIR__ . '/../../spear/manager/ai_landing_page.php';
require_once __DIR__ . '/../../spear/manager/recipient_tz.php';
require_once __DIR__ . '/../../spear/manager/totp.php';
require_once __DIR__ . '/../../spear/manager/log_classifier.php';
require_once __DIR__ . '/../../spear/manager/pretext_library.php';
require_once __DIR__ . '/../../spear/manager/scanner_detect.php';
require_once __DIR__ . '/../../spear/manager/homoglyph.php';
require_once __DIR__ . '/../../spear/manager/dmarc_lookup.php';
require_once __DIR__ . '/../../spear/manager/login_throttle.php';
require_once __DIR__ . '/../../spear/manager/secret_at_rest.php';
require_once __DIR__ . '/../../spear/manager/customer_report_aggregator.php';
require_once __DIR__ . '/../../spear/manager/bounce_detection.php';
require_once __DIR__ . '/../../spear/manager/ab_variants.php';
