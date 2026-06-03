<?php
/**
 * Phase 3.51: CSV streaming endpoint for the audit-log viewer.
 *
 * Same filter shape as the audit_log_query dispatcher; we just stream
 * CSV instead of returning JSON. Dedicated endpoint because the
 * dispatcher Content-Type is application/json and overriding it
 * mid-response is finicky.
 */
require_once(dirname(__FILE__) . '/manager/session_manager.php');
require_once(dirname(__FILE__) . '/manager/common_functions.php');
require_once(dirname(__FILE__) . '/manager/log_classifier.php');
require_once(dirname(__FILE__) . '/manager/audit_log_query.php');

isSessionValid(true);

// Operator passes filters as query string; we cap export at 5000 rows
// so a runaway filter doesn't pin the FPM worker.
$filters = [
    'kind'      => $_GET['kind']      ?? '',
    'severity'  => $_GET['severity']  ?? '',
    'username'  => $_GET['username']  ?? '',
    'search'    => $_GET['search']    ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to'   => $_GET['date_to']   ?? '',
    'limit'     => 5000,
    'offset'    => 0,
];

$r = audit_log_query($conn, $filters);
$csv = audit_log_rows_to_csv($r['rows']);

$filename = 'taphish-audit-' . gmdate('Ymd-Hi') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($csv));
header('Cache-Control: private, no-store');

if (function_exists('logIt')) {
    logIt('Audit log exported: ' . count($r['rows']) . ' rows');
}

echo $csv;
