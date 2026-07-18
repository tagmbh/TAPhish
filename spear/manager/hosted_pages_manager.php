<?php
/**
 * Hosted Pages manager — FEATURE-R2.4 in-app landing deploy dispatcher.
 * Actions (all operator-tier via authz.php):
 *   landing_deploy_targets  -> {targets[], sources[]}
 *   landing_deploy          -> deploy source landing to a look-alike host + verify
 *   landing_deploy_verify   -> re-check a host over HTTPS
 * Engine: landing_deploy.php (pure/IO, unit-tested). Paths are derived from the
 * app location: wwwBase = ~/www, sniperhostBase = spear/sniperhost.
 */
require_once(dirname(__FILE__) . '/session_manager.php');
if (isSessionValid() == false)
    die("Access denied");
csrf_require();
//-------------------------------------------------------
date_default_timezone_set('UTC');
header('Content-Type: application/json');
require_once(dirname(__FILE__) . '/landing_deploy.php');

if (isset($_POST)) {
    $POSTJ = json_decode(file_get_contents('php://input'), true);

    if (isset($POSTJ['action_type'])) {
        require_once(dirname(__FILE__) . '/authz.php');
        taphish_require_authorize_or_die($conn, (string)$POSTJ['action_type'], []);

        $wwwBase        = dirname(__FILE__, 4);                  // /home/azitufem/www
        $sniperhostBase = dirname(__FILE__, 2) . '/sniperhost';  // spear/sniperhost
        $postUrl        = 'https://deepaudit.ch/track.php';

        if ($POSTJ['action_type'] == 'landing_deploy_targets') {
            echo json_encode([
                'result'  => 'success',
                'targets' => taphish_landing_deploy_list_targets($wwwBase),
                'sources' => taphish_landing_deploy_list_sources($sniperhostBase),
            ], JSON_INVALID_UTF8_IGNORE);
        }

        if ($POSTJ['action_type'] == 'landing_deploy') {
            $src = taphish_landing_deploy_resolve_source(
                $sniperhostBase,
                (string)($POSTJ['source_kind'] ?? ''),
                (string)($POSTJ['source_name'] ?? '')
            );
            if (!$src['ok']) {
                echo json_encode(['result' => 'failed', 'error' => 'source: ' . $src['error']]);
            } else {
                $host = (string)($POSTJ['host'] ?? '');
                $res  = taphish_landing_deploy_run($src['dir'], $host, $wwwBase, $postUrl, date('Ymd'));
                if (!$res['ok']) {
                    echo json_encode(['result' => 'failed', 'error' => 'target: ' . $res['error']]);
                } else {
                    // Host is proven valid (run resolved it) → safe to build the URL.
                    $url = 'https://' . $host . '/';
                    logIt('Landing deployed: source=' . $src['dir'] . ' host=' . $host);
                    echo json_encode([
                        'result'  => 'success',
                        'host'    => $host,
                        'url'     => $url,
                        'written' => $res['written'],
                        'verify'  => taphish_landing_deploy_verify($url),
                    ], JSON_INVALID_UTF8_IGNORE);
                }
            }
        }

        if ($POSTJ['action_type'] == 'landing_deploy_verify') {
            $host = (string)($POSTJ['host'] ?? '');
            // Validate the host against the allow-list before building a URL.
            $t = taphish_landing_deploy_resolve_target($host, $wwwBase);
            if (!$t['ok']) {
                echo json_encode(['result' => 'failed', 'error' => $t['error']]);
            } else {
                echo json_encode([
                    'result' => 'success',
                    'host'   => $host,
                    'verify' => taphish_landing_deploy_verify('https://' . $host . '/'),
                ], JSON_INVALID_UTF8_IGNORE);
            }
        }
    }
}
