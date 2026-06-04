<?php
/**
 * Phase 3.55: streaming endpoint for the operator-hosted site bundle.
 *
 * Dedicated endpoint (not the JSON dispatcher) because we stream a zip with its
 * own Content-Type. Builds cloned/<slug>/ with the POST/tracker URLs substituted
 * and sends it as a download. RBAC: operator+ via lookalike_build_bundle.
 */
require_once(dirname(__FILE__) . '/manager/session_manager.php');
require_once(dirname(__FILE__) . '/manager/common_functions.php');
require_once(dirname(__FILE__) . '/manager/authz.php');
require_once(dirname(__FILE__) . '/manager/site_bundle.php');

isSessionValid(true);
taphish_require_authorize_or_die($conn, 'lookalike_build_bundle', ['engagement_id' => isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : null]);

$slug       = (string) ($_GET['slug'] ?? '');
$postUrl    = (string) ($_GET['post_url'] ?? '');
$trackerUrl = (string) ($_GET['tracker_url'] ?? '');

if ($postUrl === '') {
    // Default the capture POST back to this host's track.php.
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO']
        ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $postUrl = $host !== '' ? ($proto . '://' . $host . '/track.php') : '/track.php';
}

$res = site_bundle_build($slug, $postUrl, $trackerUrl);
if ($res === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['result' => 'failed', 'error' => 'No cloned page with that slug, or bundling unavailable.']);
    exit;
}

$data = (string) file_get_contents($res['path']);
@unlink($res['path']);

$filename = 'taphish-bundle-' . $slug . '-' . gmdate('Ymd-Hi') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: private, no-store');

if (function_exists('logIt')) {
    logIt('Look-alike bundle downloaded: ' . $slug);
}

echo $data;
