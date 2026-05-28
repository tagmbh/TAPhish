<?php

/**
 * Loads pure helpers under test without dragging in DB/session machinery.
 */

require_once __DIR__ . '/../../spear/manager/filters.php';
require_once __DIR__ . '/../../spear/manager/site_cloner_filters.php';
require_once __DIR__ . '/../../spear/manager/csrf.php';
require_once __DIR__ . '/../../spear/manager/password_hash_helper.php';
require_once __DIR__ . '/../../spear/manager/mail_presets.php';
require_once __DIR__ . '/../../spear/manager/bounce_detection.php';
