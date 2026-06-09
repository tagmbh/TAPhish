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
require_once dirname(__FILE__, 3) . '/manager/beef_integration.php';
require_once dirname(__FILE__, 2) . '/ClonedSite.php';

if (!isSessionValid()) {
    http_response_code(403);
    die('Access denied');
}
csrf_require();

header('Content-Type: application/json');

$POSTJ = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($POSTJ) || !isset($POSTJ['action_type'])) {
    echo json_encode(['result' => 'failed', 'error' => 'Missing action_type']);
    return;
}

// RBAC default-deny: cloning is an operator-level capability. Without this a
// read-only user could clone/delete sites via this endpoint, which previously
// only checked session + CSRF. All three cloner actions map to 'site_clone'.
require_once dirname(__FILE__, 3) . '/manager/authz.php';
taphish_require_authorize_or_die($conn, 'site_clone');

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
    global $conn;
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
    // Phase 3.52 task 5: optional BeEF hook injection.
    // Operator opts in per-clone via the SiteCloner form. We resolve the
    // snippet here (not in ClonedSite) so the settings lookup stays at
    // the dispatcher layer; if BeEF isn't configured, we silently drop
    // the toggle and surface a warning to the JS layer.
    $beefRequested = !empty($POSTJ['beef_hook_enabled']);
    $beefActuallyOn = false;
    if ($beefRequested && isset($conn) && $conn instanceof mysqli) {
        $bs = beef_settings_load($conn);
        if ($bs !== null && $bs['base_url'] !== '') {
            $opts['beef_hook_snippet'] = beef_hook_snippet($bs['base_url']);
            $beefActuallyOn = $opts['beef_hook_snippet'] !== '';
        }
    }
    $cloner = new ClonedSite($url, $slug, $opts);
    $result = $cloner->fetchAndSave();
    if ($result['ok']) {
        // Persist per-clone metadata (toggle + future engagement_id).
        if (isset($conn) && $conn instanceof mysqli) {
            taphish_clone_meta_upsert($conn, (string) $result['slug'], $beefActuallyOn);
        }
        logIt('Site cloned: ' . $result['slug'] . ' from ' . $result['url']
            . ($beefActuallyOn ? ' [+BeEF hook]' : ''));
        $extra = [];
        if ($beefRequested && !$beefActuallyOn) {
            $extra['beef_warning'] = 'BeEF hook requested but BeEF settings are not configured — clone written without the hook.';
        }
        echo json_encode(['result' => 'success'] + $result + $extra);
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
