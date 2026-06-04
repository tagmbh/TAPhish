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
require_once __DIR__ . '/../../spear/manager/osint_shodan.php';
require_once __DIR__ . '/../../spear/manager/ai_landing_page.php';
require_once __DIR__ . '/../../spear/manager/recipient_tz.php';
require_once __DIR__ . '/../../spear/manager/totp.php';
require_once __DIR__ . '/../../spear/manager/log_classifier.php';
require_once __DIR__ . '/../../spear/manager/pretext_library.php';
require_once __DIR__ . '/../../spear/manager/scanner_detect.php';
require_once __DIR__ . '/../../spear/manager/homoglyph.php';
require_once __DIR__ . '/../../spear/manager/domain_check.php';
require_once __DIR__ . '/../../spear/manager/dmarc_lookup.php';
require_once __DIR__ . '/../../spear/manager/capture_alerting.php';
require_once __DIR__ . '/../../spear/manager/login_throttle.php';
require_once __DIR__ . '/../../spear/manager/secret_at_rest.php';
require_once __DIR__ . '/../../spear/manager/customer_report_aggregator.php';
require_once __DIR__ . '/../../spear/manager/bounce_detection.php';
require_once __DIR__ . '/../../spear/manager/ab_variants.php';
require_once __DIR__ . '/../../spear/manager/engagement.php';
require_once __DIR__ . '/../../spear/manager/mx_classify.php';
require_once __DIR__ . '/../../spear/manager/web_fingerprint.php';
require_once __DIR__ . '/../../spear/manager/toolset_checks.php';
require_once __DIR__ . '/../../spear/manager/dkim_helper.php';
require_once __DIR__ . '/../../spear/manager/recipient_import.php';
require_once __DIR__ . '/../../spear/manager/preflight_checks.php';
require_once __DIR__ . '/../../spear/manager/beef_integration.php';
require_once __DIR__ . '/../../spear/manager/landing_library.php';
require_once __DIR__ . '/../../spear/manager/engagement_report.php';
require_once __DIR__ . '/../../spear/manager/audit_log_query.php';
require_once __DIR__ . '/../../spear/manager/telegram_alerting.php';
require_once __DIR__ . '/../../spear/manager/authz.php';
require_once __DIR__ . '/../../spear/manager/api_token.php';
require_once __DIR__ . '/../../spear/manager/lookalike_deploy.php';
require_once __DIR__ . '/../../spear/manager/site_bundle.php';
require_once __DIR__ . '/../../spear/manager/recipient_reencrypt.php';
require_once __DIR__ . '/../../spear/manager/backup_helper.php';
require_once __DIR__ . '/../../spear/manager/backup_archive.php';
