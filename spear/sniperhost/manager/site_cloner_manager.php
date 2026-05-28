<?php
/**
 * JSON action dispatcher for the Site Cloner admin page.
 *
 * Actions:
 *   clone_site   { url, slug, tracker_url?, allow_private?, force? }
 *   list_clones  {}
 *   delete_clone { slug }
 */

require_once dirname(__FILE__, 3) . '/config/db.php';
require_once dirname(__FILE__, 3) . '/manager/session_manager.php';
require_once dirname(__FILE__, 3) . '/manager/common_functions.php';
require_once dirname(__FILE__, 2) . '/ClonedSite.php';

if (!isSessionValid()) {
    http_response_code(403);
    die('Access denied');
}

header('Content-Type: application/json');

$POSTJ = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($POSTJ) || !isset($POSTJ['action_type'])) {
    echo json_encode(['result' => 'failed', 'error' => 'Missing action_type']);
    return;
}

switch ($POSTJ['action_type']) {
    case 'clone_site':
        action_clone_site($POSTJ);
        break;
    case 'list_clones':
        action_list_clones();
        break;
    case 'delete_clone':
        action_delete_clone($POSTJ);
        break;
    default:
        echo json_encode(['result' => 'failed', 'error' => 'Unknown action_type']);
}

function action_clone_site(array $POSTJ): void
{
    $url  = trim((string) ($POSTJ['url'] ?? ''));
    $slug = (string) ($POSTJ['slug'] ?? '');
    if ($url === '' || $slug === '') {
        echo json_encode(['result' => 'failed', 'error' => 'url and slug are required']);
        return;
    }
    $opts = [
        'tracker_url'     => isset($POSTJ['tracker_url']) ? trim((string) $POSTJ['tracker_url']) : null,
        'allow_private'   => !empty($POSTJ['allow_private']),
        'force'           => !empty($POSTJ['force']),
        'download_css'    => !isset($POSTJ['download_css']) || (bool) $POSTJ['download_css'],
        'download_images' => !isset($POSTJ['download_images']) || (bool) $POSTJ['download_images'],
    ];
    if ($opts['tracker_url'] === '') {
        $opts['tracker_url'] = null;
    }
    $cloner = new ClonedSite($url, $slug, $opts);
    $result = $cloner->fetchAndSave();
    if ($result['ok']) {
        logIt('Site cloned: ' . $result['slug'] . ' from ' . $result['url']);
        echo json_encode(['result' => 'success'] + $result);
    } else {
        echo json_encode(['result' => 'failed', 'error' => $result['error'] ?? 'Unknown error']);
    }
}

function action_list_clones(): void
{
    echo json_encode(['result' => 'success', 'clones' => ClonedSite::listClones()]);
}

function action_delete_clone(array $POSTJ): void
{
    $slug = (string) ($POSTJ['slug'] ?? '');
    if ($slug === '') {
        echo json_encode(['result' => 'failed', 'error' => 'slug is required']);
        return;
    }
    if (ClonedSite::deleteClone($slug)) {
        logIt('Site clone deleted: ' . $slug);
        echo json_encode(['result' => 'success']);
    } else {
        echo json_encode(['result' => 'failed', 'error' => 'Slug not found or could not be deleted']);
    }
}
