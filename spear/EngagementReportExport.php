<?php
/**
 * Phase 3.47: PDF report streaming endpoint.
 *
 * Dedicated endpoint (not a dispatcher action) because the response
 * body is a binary PDF — the dispatcher path sets
 * Content-Type: application/json early and that's hard to override
 * cleanly. This file is invoked via:
 *
 *   GET EngagementReportExport?engagement_id=<id>
 *
 * Session-gated like every other operator page. Audit-log emits a
 * SYS/ok line whether the report fires successfully or fails so we
 * have a record of when reports were generated.
 */
require_once(dirname(__FILE__) . '/manager/session_manager.php');
require_once(dirname(__FILE__) . '/manager/common_functions.php');
require_once(dirname(__FILE__) . '/manager/engagement.php');
require_once(dirname(__FILE__) . '/manager/engagement_report.php');
require_once(dirname(__FILE__) . '/manager/secret_at_rest.php');

isSessionValid(true);

$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
if ($engagementId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'engagement_id is required';
    exit;
}

$data = engagement_report_aggregate($conn, $engagementId);
if ($data === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Engagement not found';
    exit;
}

if (function_exists('logIt')) {
    logIt('Engagement report generated: id=' . $engagementId
        . ' (' . (string) ($data['engagement']['slug'] ?? '') . ')');
}

$pdf = engagement_report_render_pdf($data);

$slug = (string) ($data['engagement']['slug'] ?? 'engagement');
$slug = preg_replace('/[^a-zA-Z0-9-]+/', '-', $slug) ?: 'engagement';
$filename = 'taphish-report-' . $slug . '-' . gmdate('Ymd-Hi') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
