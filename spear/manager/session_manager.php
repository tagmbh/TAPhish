<?php
require_once(dirname(__FILE__) . '/csrf.php');
require_once(dirname(__FILE__) . '/password_hash_helper.php');
require_once(dirname(__FILE__) . '/security_headers.php');
require_once(dirname(__FILE__) . '/totp.php');
require_once(dirname(__FILE__) . '/secret_at_rest.php');
taphish_emit_security_headers();
if (session_status() === PHP_SESSION_NONE) {
   @ob_start();
   session_start();
   if (empty($_SESSION['_csrf'])) {
      $_SESSION['_csrf'] = _csrf_make_token();
   }
   session_write_close();	//prevent access denied lock error
}
if (file_exists(dirname(__FILE__,2) . '/config/db.php'))
	require_once(dirname(__FILE__,2) . '/config/db.php');
else
	die("Can not find db.php. Visit <a href='/install'>here</a> to install TAPhish");	//shows if login page is opened before install
require_once(dirname(__FILE__) . '/common_functions.php');
require_once(dirname(__FILE__) . '/mail_presets.php');
date_default_timezone_set('UTC');
// Idempotently top up TAPhish-shipped mail-sender presets so existing
// installs gain new providers without manual SQL. Cheap: one indexed
// SELECT against tb_store; INSERT only on miss.
if (isset($conn) && $conn instanceof mysqli) {
	taphish_ensure_mail_presets($conn);
	totp_ensure_schema($conn);	//Phase 3.25: add totp_secret + totp_enabled columns if missing
	totp_ensure_recovery_schema($conn);	//Phase 3.31: create tb_totp_recovery_codes if missing
	// Phase 3.39: create + seed the pretext library on first boot.
	if (is_file(dirname(__FILE__) . '/pretext_library.php')) {
		require_once(dirname(__FILE__) . '/pretext_library.php');
		taphish_ensure_pretext_schema($conn);
		taphish_ensure_pretext_seeds($conn);
		// 2026-06-08 one-time heal: literal `https://example.com/REPLACE-WITH-TRACKER-URL`
		// in seed/cloned bodies + short-form 'html' mail_content_type. Idempotent;
		// once healed, the LIKE/equality filter matches nothing.
		taphish_heal_pretext_clone_bugs($conn);
	}
	// Phase 3.42: add tb_data_webform_submit.code_2fa if missing.
	if (is_file(dirname(__FILE__) . '/capture_alerting.php')) {
		require_once(dirname(__FILE__) . '/capture_alerting.php');
		taphish_ensure_capture_schema($conn);
		// Phase 3.45e: add is_2fa_capture + repeat_webhook_sent.
		taphish_ensure_capture_schema_v2($conn);
	}
	// Phase 3.43a: create tb_core_engagement on first boot.
	if (is_file(dirname(__FILE__) . '/engagement.php')) {
		require_once(dirname(__FILE__) . '/engagement.php');
		taphish_engagement_ensure_schema($conn);
		// Phase 3.45b: add engagement_id FK on tb_core_mailcamp_list.
		taphish_engagement_ensure_campaign_fk_column($conn);
		// Phase 3.56: wizard_step + wizard_state for a resumable wizard.
		taphish_engagement_ensure_wizard_columns($conn);
		// Phase 3.48b: engagement_id on tb_core_mailcamp_user_group + one-time
		// backfill, so recipient PII can be scoped per engagement.
		taphish_user_group_ensure_engagement_column($conn);
	}
	// Phase 3.48: RBAC - tb_main.role (+ admin auto-promote) and the
	// engagement-membership join table. Idempotent; safe every boot.
	if (is_file(dirname(__FILE__) . '/authz.php')) {
		require_once(dirname(__FILE__) . '/authz.php');
		taphish_authz_ensure_role_column($conn);
		taphish_authz_ensure_initial_super_admins($conn);
		taphish_authz_ensure_engagement_member_table($conn);
	}
	if (is_file(dirname(__FILE__) . '/api_token.php')) {
		require_once(dirname(__FILE__) . '/api_token.php');
		taphish_api_token_ensure_table($conn);
	}
	// Phase 3.45a: add is_scanner + scanner_reason columns on the live tables.
	if (is_file(dirname(__FILE__) . '/scanner_detect.php')) {
		require_once(dirname(__FILE__) . '/scanner_detect.php');
		taphish_scanner_ensure_schema($conn);
	}
	// Phase 3.52: per-clone metadata table (BeEF hook toggle lives here).
	if (is_file(dirname(__FILE__) . '/beef_integration.php')) {
		require_once(dirname(__FILE__) . '/beef_integration.php');
		taphish_clone_meta_ensure_schema($conn);
	}
}
// Phase 3.9: detect operators still using the bootstrap "sniperphish"
// password so the JS guard in z_menu.php can redirect them to
// SettingsUser. Format-agnostic: works for legacy SHA-256 and bcrypt.
$GLOBALS['TAPHISH_MUST_CHANGE_PWD'] = false;
if (!empty($_SESSION['username']) && isset($conn) && $conn instanceof mysqli) {
	$_taphish_uname = $_SESSION['username'];
	$_taphish_stmt = $conn->prepare("SELECT password FROM tb_main WHERE username = ?");
	if ($_taphish_stmt) {
		$_taphish_stmt->bind_param('s', $_taphish_uname);
		$_taphish_stmt->execute();
		$_taphish_res = $_taphish_stmt->get_result();
		$_taphish_row = $_taphish_res ? $_taphish_res->fetch_assoc() : null;
		$_taphish_stmt->close();
		if ($_taphish_row && verify_user_password('sniperphish', $_taphish_row['password'])) {
			$GLOBALS['TAPHISH_MUST_CHANGE_PWD'] = true;
		}
	}
}
$entry_time = (new DateTime())->format('d-m-Y h:i A');
error_reporting(E_ERROR | E_PARSE); //Disable warnings
//-----------------------------

function validateLogin($username,$pwd){
	global $conn;
	// Phase 3.25: also pull totp_secret + totp_enabled so we can decide
	// whether a second factor is required without a second query. Wrapped
	// in defined()-style defense in case the schema migration hasn't run
	// yet (taphish_ensure_schema is idempotent but new installs that
	// haven't completed the session_manager boot block could race).
	$stmt = $conn->prepare("SELECT password, totp_secret, totp_enabled FROM tb_main WHERE username=?");
	if ($stmt === false) {
		// Fall back to password-only on schema-missing.
		$stmt = $conn->prepare("SELECT password FROM tb_main WHERE username=?");
	}
	$stmt->bind_param('s', $username);
	$stmt->execute();
	$result = $stmt->get_result();
	if ($result->num_rows === 0) {
		return false;
	}
	$row = $result->fetch_assoc();
	$stored = $row['password'];
	if (!verify_user_password($pwd, $stored)) {
		return false;
	}
	if (password_should_rehash($stored)) {
		// Transparent upgrade: re-hash with bcrypt and persist.
		$newHash = hash_user_password($pwd);
		$upd = $conn->prepare("UPDATE tb_main SET password=? WHERE username=?");
		$upd->bind_param('ss', $newHash, $username);
		$upd->execute();
		$upd->close();
	}
	// Phase 3.25: if the user enrolled in TOTP, return 'pending_totp'
	// instead of completing the login. The caller renders a code-entry
	// form and finishes the login via completeTotpLogin().
	if (!empty($row['totp_enabled']) && !empty($row['totp_secret'])) {
		return 'pending_totp';
	}
	updateLoginLogout($conn, $username, $GLOBALS['entry_time'], true);
	$os = getOSType();
	if(!isProcessRunning($conn,$os))
		startProcess($os);
	return true;
}

// Phase 3.25: second-factor completion. Called by spear/index.php after
// the user submits the 6-digit code from their authenticator app. We
// re-fetch the stored secret here (instead of trusting a value held in
// $_SESSION) so an attacker who can write to $_SESSION still can't bypass
// the check — the secret is loaded from tb_main on every verification.
function completeTotpLogin($username, $code) {
	global $conn;
	$stmt = $conn->prepare("SELECT totp_secret, totp_enabled FROM tb_main WHERE username=?");
	if ($stmt === false) return false;
	$stmt->bind_param('s', $username);
	$stmt->execute();
	$row = $stmt->get_result()->fetch_assoc();
	$stmt->close();
	if (!$row || empty($row['totp_enabled']) || empty($row['totp_secret'])) {
		return false;
	}
	$accepted = totp_verify_code($row['totp_secret'], $code, time());
	if (!$accepted) {
		// Phase 3.31: fall back to a single-use recovery code so an
		// operator who lost their authenticator can still finish login.
		// totp_consume_recovery_code only returns true when it has
		// already marked the matching row used_at = NOW(), so the same
		// code can't be replayed in a follow-up attempt.
		$accepted = totp_consume_recovery_code($conn, $username, (string) $code);
	}
	if (!$accepted) {
		return false;
	}
	updateLoginLogout($conn, $username, $GLOBALS['entry_time'], true);
	$os = getOSType();
	if (!isProcessRunning($conn, $os)) {
		startProcess($os);
	}
	return true;
}

function isSessionValid($f_redirection=false){	//this check refreshes session expiry
	if (isset($_SESSION['username'])) {
		createSession(false,$_SESSION['username']);
		return true;
	}
	// Phase 3.48: accept a per-operator API bearer token in place of the
	// session cookie. On success it authenticates as that operator for this
	// request; the request still passes the RBAC guard like any session.
	if (function_exists('taphish_extract_bearer_token') && function_exists('taphish_api_token_authenticate')) {
		$bearer = taphish_extract_bearer_token();
		if ($bearer !== '' && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
			$u = taphish_api_token_authenticate($GLOBALS['conn'], $bearer);
			if ($u !== null) {
				$_SESSION['username'] = $u;
				$_SESSION['_api_token_auth'] = true;
				return true;
			}
		}
	}
	terminateSession($f_redirection); //redirect to home if true
	return false;
}

function setInfoCookie(&$conn, &$username){
	$row = getLoginLogoutInfo($conn, $username);
	$DTime_info = getTimeInfo($conn);
	$row['timezone'] = $DTime_info['time_zone']['timezone'];
	$last_login_hist = json_decode($row['last_login']);
	$last_login = ($last_login_hist==null || count($last_login_hist)==1)?$last_login_hist[0]:$last_login_hist[1];
	$row['last_login'] = getInClientTime_FD($DTime_info,$last_login,null,'d-m-Y h:i A');
	if($row['last_login'] == '-') //first time login
		$row['last_login'] = getInClientTime_FD($DTime_info,(new DateTime())->format('d-m-Y h:i A'),null,'d-m-Y h:i A');
	setcookie("c_data",base64_encode(json_encode($row)), ["path" => "/", "SameSite" => "Strict", "HttpOnly" => false]);
}

function updateLoginLogout($conn, $username, $entry_time, $islogin){
	$access_info = getLoginLogoutInfo($conn, $username);

	if($islogin){
		$login_logout_hist = getUpdatedLoginLogoutHist($entry_time, json_decode($access_info['last_login']));
		$stmt = $conn->prepare("UPDATE tb_main SET last_login=? WHERE username=?");
		logIt('Account login',$username);
	}
	else{
		$login_logout_hist = getUpdatedLoginLogoutHist($entry_time, json_decode($access_info['last_logout']));
		$stmt = $conn->prepare("UPDATE tb_main SET last_logout=? WHERE username=?");
		logIt("Account logout");
	}
	$stmt->bind_param('ss', json_encode($login_logout_hist),$username);
	$stmt->execute();
}

function getUpdatedLoginLogoutHist(&$entry_time, &$login_logout_hist){
	if($login_logout_hist==null)
		$login_logout_hist[0]=$entry_time;
	elseif(count($login_logout_hist) == 1)
		$login_logout_hist[1]=$entry_time;
	else{
		$login_logout_hist[0]=$login_logout_hist[1];
		$login_logout_hist[1]=$entry_time;
	}
	return $login_logout_hist;
}

function getLoginLogoutInfo(&$conn, &$username){
	$stmt = $conn->prepare("SELECT name,username,dp_name,last_login,last_logout FROM tb_main WHERE username=?");
	$stmt->bind_param('s', $username);
	$stmt->execute();
	$result = $stmt->get_result();
	if($result->num_rows > 0)
		return $result->fetch_assoc();
}

//-----------------------------------Start Public Access-------------------------------
function amIPublic($tk_id,$campaign_id,$tracker_id=""){
	global $conn;

	if(empty($tracker_id))
		$ctrl_ids = json_encode([$campaign_id]);
	else
		$ctrl_ids = json_encode([$campaign_id,$tracker_id]);

	$stmt = $conn->prepare("SELECT COUNT(*) FROM tb_access_ctrl WHERE tk_id=? AND ctrl_ids=?");
	$stmt->bind_param("ss", $tk_id,$ctrl_ids);
	$stmt->execute();
	$row = $stmt->get_result()->fetch_row();
	if($row[0] > 0)
		return true;
	else
		return false;
}

//====================================

if (isset($_POST)) {
	$POSTJ = json_decode(file_get_contents('php://input'),true);

	if(isset($POSTJ['action_type'])){
		// State-changing actions require a valid CSRF token. re_login is the
		// recovery path after session_destroy() so the stored token is gone;
		// it relies on the credentials themselves for authentication.
		$csrf_exempt_actions = ['re_login'];
		if(!in_array($POSTJ['action_type'], $csrf_exempt_actions, true))
			csrf_require();

		if(isset($POSTJ['tk_id']))
			if($POSTJ['action_type'] == "manage_dashboard_access"){
				if(isset($POSTJ['campaign_id']) && isset($POSTJ['tracker_id']))
					manageDashboardAccess($POSTJ['tk_id'],$POSTJ['ctrl_val'],$POSTJ['campaign_id'],$POSTJ['tracker_id']);
				else
					if(isset($POSTJ['campaign_id']))
						manageDashboardAccess($POSTJ['tk_id'],$POSTJ['ctrl_val'],$POSTJ['campaign_id']);
			}
			else
			if($POSTJ['action_type'] == "get_access_info"){
				if(isset($POSTJ['campaign_id']) && isset($POSTJ['tracker_id']))
					getAccessInfo($POSTJ['tk_id'],$POSTJ['campaign_id'],$POSTJ['tracker_id']);
				else
					if(isset($POSTJ['campaign_id']))
						getAccessInfo($POSTJ['tk_id'],$POSTJ['campaign_id']);
			}

		if($POSTJ['action_type'] == "re_login")
			doReLogin($POSTJ['username'], $POSTJ['pwd']);
		if($POSTJ['action_type'] == "terminate_session")
			terminateSession(false);
	}
}

function manageDashboardAccess($tk_id,$ctrl_val,$campaign_id,$tracker_id=""){	// For web-email camp
	header('Content-Type: application/json');
	global $conn;

	if(empty($tracker_id))
		$ctrl_ids = json_encode([$campaign_id]);
	else
		$ctrl_ids = json_encode([$campaign_id,$tracker_id]);

	//delete existing entry
	$stmt = $conn->prepare("DELETE FROM tb_access_ctrl WHERE ctrl_ids = ?");
	$stmt->bind_param("s", $ctrl_ids);
	$stmt->execute();
	$stmt->close();

	if($ctrl_val == true){
		$stmt = $conn->prepare("INSERT INTO tb_access_ctrl(tk_id,ctrl_ids) VALUES(?,?)");
		$stmt->bind_param('ss', $tk_id,$ctrl_ids);
	}
	else{
		$stmt = $conn->prepare("DELETE FROM tb_access_ctrl WHERE tk_id = ?");
		$stmt->bind_param('s', $tk_id);
	}

	if ($stmt->execute() === TRUE)
		echo json_encode(['result' => 'success', 'tk_id'=> $tk_id]);	
	else 
		echo json_encode(['result' => 'failed', 'error' => 'Error in enabling/disabling access']);	
	$stmt->close();
}

function getAccessInfo($tk_id, $campaign_id, $tracker_id=""){
	header('Content-Type: application/json');
	global $conn;
	
	if(empty($tracker_id))
		$ctrl_ids = json_encode([$campaign_id]);
	else
		$ctrl_ids = json_encode([$campaign_id,$tracker_id]);

	$stmt = $conn->prepare("SELECT tk_id FROM tb_access_ctrl WHERE ctrl_ids=?");
	$stmt->bind_param("s", $ctrl_ids);
	$stmt->execute();
	$result = $stmt->get_result();
	if($row = $result->fetch_assoc())
		echo json_encode(['pub_access' => true, 'tk_id'=>$row["tk_id"]]);
	else
		echo json_encode(['pub_access' => false]);
	$stmt->close();
}
//-----------------------------------End Public Access-------------------------------

function doReLogin($username, $pwd){
	header('Content-Type: application/json');
	global $conn;

	$stmt = $conn->prepare("SELECT password FROM tb_main WHERE username=?");
	$stmt->bind_param('s', $username);
	$stmt->execute();
	$result = $stmt->get_result();
	if ($result->num_rows === 0) {
		echo json_encode(['result' => 'failed']);
		return;
	}
	$stored = $result->fetch_assoc()['password'];
	if (!verify_user_password($pwd, $stored)) {
		echo json_encode(['result' => 'failed']);
		return;
	}
	if (password_should_rehash($stored)) {
		$newHash = hash_user_password($pwd);
		$upd = $conn->prepare("UPDATE tb_main SET password=? WHERE username=?");
		$upd->bind_param('ss', $newHash, $username);
		$upd->execute();
		$upd->close();
	}
	createSession(true, $username);
	echo json_encode(['result' => 'success']);
}

function createSession($f_regenerate,$username){
	global $conn;
	// Preserve the existing CSRF token across REFRESH-style calls
	// (isSessionValid() calls this on every page load to bump the
	// cookie expiry; rotating _csrf there would invalidate the token
	// the just-rendered page embedded in window.TAPHISH_CSRF, and
	// every subsequent AJAX from that page would 403).
	$preserved_csrf = (!$f_regenerate && !empty($_SESSION['_csrf']))
		? $_SESSION['_csrf']
		: null;

	session_destroy();
	$is_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
		|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
		|| (($_SERVER['SERVER_PORT'] ?? '') == 443);
	session_set_cookie_params([
			'lifetime' => 86400,	//86400=1 day
			'secure' => $is_https,
			'httponly' => true,
			'samesite' => 'Strict'
		]);

	session_start();

	if($f_regenerate){
		header_remove('Set-Cookie');	//deletes header set by session_start(); from response
		session_regenerate_id(true);
	}

	$_SESSION['username'] = $username;
	$_SESSION['_csrf'] = $preserved_csrf ?? _csrf_make_token();	//fresh on login, preserved on refresh
	setInfoCookie($conn,$username);
}

function terminateSession($redirection=true){
	session_destroy();
	if($redirection){
		ob_end_clean();   // clear output buffer
		header("Location: /spear/");
		die();
	}
}
?>