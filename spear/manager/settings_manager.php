<?php
require_once(dirname(__FILE__) . '/session_manager.php');
require_once(dirname(__FILE__) . '/common_functions.php');
require_once(dirname(__FILE__,2) . '/libs/tcpdf_min/tcpdf.php');

if(isSessionValid() == false)
	die("Access denied");
csrf_require();
//-------------------------------------------------------
date_default_timezone_set('UTC');
$entry_time = (new DateTime())->format('d-m-Y h:i A');
header('Content-Type: application/json');

if (isset($_POST)) {
	$POSTJ = json_decode(file_get_contents('php://input'),true);

	if(isset($POSTJ['action_type'])){
		// Phase 3.48 (RBAC): default-deny guard for every action in this
		// dispatcher - unknown/unauthorised actions get 403 + {result:'forbidden'} + audit log.
		require_once(dirname(__FILE__) . '/authz.php');
		taphish_require_authorize_or_die($conn, (string)$POSTJ['action_type'], ['engagement_id' => isset($POSTJ['engagement_id']) ? (int)$POSTJ['engagement_id'] : null]);
		if($POSTJ['action_type'] == "get_user_list")
			getUserList($conn);
		if($POSTJ['action_type'] == "add_account")
			addAccount($conn,$POSTJ['name'], $POSTJ['username'], $POSTJ['mail'], $POSTJ['dp_name'], $POSTJ['current_pwd'], $POSTJ['new_pwd']);
		if($POSTJ['action_type'] == "modify_account")
			modifyAccount($conn,$POSTJ['name'], $POSTJ['username'], $POSTJ['mail'], $POSTJ['dp_name'], $POSTJ['current_pwd'], $POSTJ['new_pwd']);
		if($POSTJ['action_type'] == "delete_account")
			deleteAccount($conn,$POSTJ['id']);
		if($POSTJ['action_type'] == "get_current_user")
			getCurrentUser($conn);

		// Phase 3.25: TOTP 2FA enrollment + disable
		if($POSTJ['action_type'] == "totp_begin_enrollment")
			totpBeginEnrollment($conn);
		if($POSTJ['action_type'] == "totp_confirm_enrollment")
			totpConfirmEnrollment($conn, $POSTJ['secret'] ?? '', $POSTJ['code'] ?? '');
		if($POSTJ['action_type'] == "totp_disable")
			totpDisable($conn, $POSTJ['current_pwd'] ?? '', $POSTJ['code'] ?? '');
		if($POSTJ['action_type'] == "totp_get_status")
			totpGetStatus($conn);

		// Phase 3.31: TOTP recovery codes
		if($POSTJ['action_type'] == "totp_regenerate_recovery_codes")
			totpRegenerateRecoveryCodes($conn, $POSTJ['code'] ?? '');
		if($POSTJ['action_type'] == "totp_recovery_status")
			totpRecoveryStatus($conn);

		// Phase 3.42: capture webhook URL config (Slack/Teams/Discord)
		if($POSTJ['action_type'] == "get_capture_webhook_url") {
			require_once(dirname(__FILE__) . '/capture_alerting.php');
			echo json_encode(['result' => 'success', 'url' => taphish_get_capture_webhook_url($conn)]);
		}
		if($POSTJ['action_type'] == "set_capture_webhook_url") {
			require_once(dirname(__FILE__) . '/capture_alerting.php');
			$ok = taphish_set_capture_webhook_url($conn, trim((string)($POSTJ['url'] ?? '')));
			echo json_encode($ok ? ['result' => 'success'] : ['result' => 'failed', 'error' => 'Could not save URL.']);
			if ($ok) logIt('Capture webhook URL updated');
		}

		// ---- Phase 3.53: Telegram bot alerting --------------------------
		if($POSTJ['action_type'] == "telegram_settings_load") {
			require_once(dirname(__FILE__) . '/telegram_alerting.php');
			$cfg = taphish_get_telegram_config($conn);
			if ($cfg === null) {
				echo json_encode(['result' => 'success', 'configured' => false]);
			} else {
				echo json_encode([
					'result'       => 'success',
					'configured'   => true,
					'token_masked' => taphish_telegram_mask_token($cfg['token']),
					'chat_id'      => $cfg['chat_id'],
				]);
			}
		}
		if($POSTJ['action_type'] == "telegram_settings_save") {
			require_once(dirname(__FILE__) . '/telegram_alerting.php');
			$token  = (string)($POSTJ['token']   ?? '');
			$chatId = trim((string)($POSTJ['chat_id'] ?? ''));
			if (trim($token) === '') {
				$ok = taphish_set_telegram_config($conn, '', '');
				echo json_encode($ok ? ['result' => 'success', 'cleared' => true] : ['result' => 'failed', 'error' => 'Could not clear config']);
				if ($ok) logIt('Telegram alerting cleared');
			} else {
				// "•••" sentinel = keep existing token (operator edited chat id only).
				if (preg_match('/•/u', $token)) {
					$existing = taphish_get_telegram_config($conn);
					$token = $existing ? $existing['token'] : '';
				}
				if (!taphish_telegram_validate_token($token)) {
					echo json_encode(['result' => 'failed', 'error' => 'Invalid bot token format (expect "<digits>:<token>")']);
				} elseif (!taphish_telegram_validate_chat_id($chatId)) {
					echo json_encode(['result' => 'failed', 'error' => 'Invalid chat id (numeric, -group id, or @channel)']);
				} else {
					$ok = taphish_set_telegram_config($conn, $token, $chatId);
					echo json_encode($ok
						? ['result' => 'success']
						: ['result' => 'failed', 'error' => 'Could not save — at-rest encryption key not configured.']);
					if ($ok) logIt('Telegram alerting configured');
				}
			}
		}
		if($POSTJ['action_type'] == "telegram_test") {
			require_once(dirname(__FILE__) . '/telegram_alerting.php');
			$cfg = taphish_get_telegram_config($conn);
			if ($cfg === null) {
				echo json_encode(['result' => 'failed', 'error' => 'Telegram not configured']);
			} else {
				$ok = taphish_telegram_send($cfg['token'], $cfg['chat_id'],
					"\xe2\x9c\x85 TAPhish test message — your Telegram alerting is wired up.");
				echo json_encode(['result' => 'success', 'ok' => $ok,
					'error' => $ok ? null : 'Telegram rejected the message (check token + chat id)']);
			}
		}

		// ---- Phase 3.52: BeEF integration settings -----------------------
		// Encrypted-at-rest credentials live in tb_store; the load action
		// never returns the password (only a fixed-width mask). A "•••"
		// sentinel in the password field on save means "keep existing".
		if($POSTJ['action_type'] == "beef_settings_load") {
			require_once(dirname(__FILE__) . '/beef_integration.php');
			$s = beef_settings_load($conn);
			if ($s === null) {
				echo json_encode(['result' => 'success', 'configured' => false]);
			} else {
				echo json_encode([
					'result'          => 'success',
					'configured'      => true,
					'base_url'        => $s['base_url'],
					'username'        => $s['username'],
					'password_masked' => beef_settings_mask_password($s['password']),
				]);
			}
		}
		if($POSTJ['action_type'] == "beef_settings_save") {
			require_once(dirname(__FILE__) . '/beef_integration.php');
			$base = trim((string)($POSTJ['base_url'] ?? ''));
			$user = trim((string)($POSTJ['username'] ?? ''));
			$pass = (string)($POSTJ['password'] ?? '');
			if ($base === '') {
				beef_settings_delete($conn);
				echo json_encode(['result' => 'success', 'cleared' => true]);
				logIt('BeEF integration credentials cleared');
			} elseif (!preg_match('#^https?://#i', $base)) {
				echo json_encode(['result' => 'failed', 'error' => 'Invalid base URL — must start with http:// or https://']);
			} else {
				if ($pass === '' || preg_match('/^•+$/u', $pass)) {
					$existing = beef_settings_load($conn);
					$pass = $existing ? $existing['password'] : '';
				}
				$ok = beef_settings_save($conn, $base, $user, $pass);
				if (!$ok) {
					// beef_settings_save refuses to write plaintext when
					// the at-rest envelope is unavailable. Tell the
					// operator what to fix.
					echo json_encode([
						'result' => 'failed',
						'error'  => 'Could not save credentials — at-rest encryption key not configured. Check spear/storage/at-rest-key.bin.',
					]);
				} else {
					echo json_encode(['result' => 'success']);
				}
				if ($ok) logIt('BeEF integration credentials updated');
			}
		}
		if($POSTJ['action_type'] == "beef_test_connection") {
			require_once(dirname(__FILE__) . '/beef_integration.php');
			$s = beef_settings_load($conn);
			if ($s === null) {
				echo json_encode(['result' => 'failed', 'error' => 'BeEF settings not configured']);
			} else {
				$r = beef_authenticate($s['base_url'], $s['username'], $s['password']);
				if ($r['ok']) {
					echo json_encode(['result' => 'success', 'ok' => true]);
				} else {
					echo json_encode(['result' => 'success', 'ok' => false, 'error' => $r['err']]);
				}
			}
		}

		if($POSTJ['action_type'] == "modify_timestamp_settings")
			modifyTimestampSettings($conn, json_encode($POSTJ['time_zone']), json_encode($POSTJ['time_format']));
		if($POSTJ['action_type'] == "get_timestamp_settings")
			getTimetampSettings($conn);
		if($POSTJ['action_type'] == "get_date_time_display")
			getDateTimeDisplay($conn,$POSTJ['time_zone'],$POSTJ['date_time_format']);
		if($POSTJ['action_type'] == "modify_SP_base_URL")
			modifySPBaseURL($conn, $POSTJ['baseurl']);
		if($POSTJ['action_type'] == "clear_junk_SP_data")
			clearJunkSPData($conn);

		if($POSTJ['action_type'] == "get_logs")
			getLogs($conn,$POSTJ);
		if($POSTJ['action_type'] == "download_logs")
			downloadLogs($conn,$POSTJ['file_format']);
		if($POSTJ['action_type'] == "clear_log")
			clearLog($conn);

		//Store data
		if($POSTJ['action_type'] == "get_store_list")
			getStoreList($conn, $POSTJ['type'], (isset($POSTJ['name'])?$POSTJ['name']:""));
	}
}

//-----------------------------

function getCurrentUser($conn){
	$username = $_SESSION['username'];
	$DTime_info = getTimeInfo($conn);

	$stmt = $conn->prepare("SELECT id,name,username,contact_mail,dp_name,date FROM tb_main WHERE username=?");
	$stmt->bind_param("s", $username);
	$stmt->execute();
	$result = $stmt->get_result();
	if($result->num_rows != 0){
		$row = $result->fetch_assoc() ;
		$row['date'] = getInClientTime_FD($DTime_info,$row['date'],null,'d-m-Y h:i A');
		echo json_encode($row, JSON_INVALID_UTF8_IGNORE) ;
	}
	else
		echo json_encode(['error' => 'No data']);				
	$stmt->close();
}

function getUserList($conn){
	$resp = [];
	$DTime_info = getTimeInfo($conn);
	$result = mysqli_query($conn, "SELECT id,name,username,contact_mail,dp_name,date,last_login FROM tb_main");
	if(mysqli_num_rows($result) > 0){
		foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row){
			$row['date'] = getInClientTime_FD($DTime_info,$row['date'],null,'d-m-Y h:i A');
			$row['last_login'] = getInClientTime_FD($DTime_info,json_decode($row['last_login'])[0],null,'d-m-Y h:i A');
        	array_push($resp,$row);
		}
		echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
	}
	else
		echo json_encode(['error' => 'No data']);
}

function addAccount($conn,$name,$username,$contact_mail,$dp_name,$current_pwd,$new_pwd){
	if(checkAnIDExist($conn,$username,'username','tb_main'))
		die(json_encode(['result' => 'failed', 'error' => 'Account with this username already exist!']));

	if(isCurrentPwdCorrect($conn,$current_pwd)){
		$new_pwd_hash = hash_user_password($new_pwd);
		$stmt = $conn->prepare("INSERT INTO tb_main(name, username, password, contact_mail, dp_name, date) VALUES(?,?,?,?,?,?)");
		$stmt->bind_param('ssssss', $name,$username,$new_pwd_hash,$contact_mail,$dp_name,$GLOBALS['entry_time']);

		if ($stmt->execute() === TRUE)
			echo json_encode(['result' => 'success']);	
		else 
			echo json_encode(['result' => 'failed', 'error' => 'Error saving data! '.$stmt->error]);	
		$stmt->close();
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Authorization failed! Your password is incorrect!']));
}


function modifyAccount($conn,$name,$username,$contact_mail,$dp_name,$current_pwd,$new_pwd){	
	if(isCurrentPwdCorrect($conn,$current_pwd)){	//current password is correct
		if($new_pwd == ''){	//update email only
			$stmt = $conn->prepare("UPDATE tb_main SET name=?, contact_mail=?, dp_name=? WHERE username=?");
			$stmt->bind_param('ssss', $name,$contact_mail,$dp_name,$username);
			if ($stmt->execute() === TRUE){
				echo(json_encode(['result' => 'success']));	
			}
			else 
				echo(json_encode(['result' => 'failed', 'error' => 'Contact mail update failed!']));
		}
		else{	//update all
			$new_pwd_hash = hash_user_password($new_pwd);
			$stmt = $conn->prepare("UPDATE tb_main SET name=?, password=?, contact_mail=?, dp_name=? WHERE username=?");
			$stmt->bind_param('sssss', $name,$new_pwd_hash,$contact_mail,$dp_name,$username);
			if ($stmt->execute() === TRUE)
				echo(json_encode(['result' => 'success']));	
			else 
				echo(json_encode(['result' => 'failed', 'error' => 'Update failed!']));
		}
		setInfoCookie($conn,$_SESSION['username']);	//sets c_data cookie
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Authorization failed! Your password is incorrect!']));
}

function isCurrentPwdCorrect(&$conn, &$current_pwd){
	$current_username = $_SESSION['username'];

	$stmt = $conn->prepare("SELECT password FROM tb_main WHERE username=?");
	$stmt->bind_param('s', $current_username);
	$stmt->execute();
	$result = $stmt->get_result();
	if ($result->num_rows === 0) {
		return false;
	}
	$stored = $result->fetch_assoc()['password'];
	if (!verify_user_password($current_pwd, $stored)) {
		return false;
	}
	if (password_should_rehash($stored)) {
		$newHash = hash_user_password($current_pwd);
		$upd = $conn->prepare("UPDATE tb_main SET password=? WHERE username=?");
		$upd->bind_param('ss', $newHash, $current_username);
		$upd->execute();
		$upd->close();
	}
	return true;	//current password is correct (verify succeeded above)
}

function deleteAccount($conn,$id){
	if($id == 1)
		die(json_encode(['result' => 'failed', 'error' => 'Admin account can not be deleted!']));
	else{
		$stmt = $conn->prepare("DELETE FROM tb_main WHERE id=?");
		$stmt->bind_param("s", $id);
		if ($stmt->execute() === TRUE)
			echo(json_encode(['result' => 'success']));	
		else 
			echo(json_encode(['result' => 'failed', 'error' => 'Error deleting account!']));	
		$stmt->close();
	}
}

//----------------General Settings-----------
function getDateTimeDisplay($conn,$time_zone,$date_time_format){
	if(substr($date_time_format, 0, strlen('Unix Timestamp-seconds')) === 'Unix Timestamp-seconds')
		echo json_encode(['result' => round(microtime(true))]);
	elseif(substr($date_time_format, 0, strlen('Unix Timestamp-milliseconds')) === 'Unix Timestamp-milliseconds')
		echo json_encode(['result' => round(microtime(true) * 1000)]);
	else
		echo json_encode(['result' => getInClientTime(null,round(microtime(true) * 1000),$time_zone,$date_time_format)]);
}

function modifySPBaseURL($conn,$baseurl){
	$pieces = parse_url($baseurl);
	$server_protocol = $pieces['scheme'];
	$domain = $pieces['host'];

	$stmt = $conn->prepare("UPDATE tb_main_variables SET server_protocol=?, domain=?, baseurl=? WHERE id=1");
	$stmt->bind_param('sss', $server_protocol,$domain,$baseurl);
	if ($stmt->execute() === TRUE){
		echo(json_encode(['result' => 'success']));	
	}
	else 
		echo(json_encode(['result' => 'failed', 'error' => 'SP base URL update failed!']));
}

function modifyTimestampSettings($conn, $time_zone, $time_format){
	$stmt = $conn->prepare("UPDATE tb_main_variables SET time_zone=?, time_format =? where id=1");
	$stmt->bind_param('ss', $time_zone, $time_format);
	
	if ($stmt->execute() === TRUE)
		echo json_encode(['result' => 'success']);	
	else 
		echo json_encode(['result' => 'failed', 'error' => 'Update failed!']);	
	$stmt->close();
}

function getTimetampSettings($conn){
	$result = mysqli_query($conn, "SELECT time_zone,time_format,baseurl FROM tb_main_variables")->fetch_assoc();
	$result['time_zone'] = json_decode($result['time_zone']);
	$result['time_format'] = json_decode($result['time_format']);
	echo json_encode($result);
}

//---Clear junk data-------
//Clear junk tracker images
function clearJunkSPData(&$conn){
	try{
		clearJunkSPDataAction($conn);
		echo json_encode(['result' => 'success']);
	}
	catch(Exception $e) {
		echo json_encode(['result' => 'failed', 'error' => $e->getMessage()]);
	}
}
function clearJunkSPDataAction(&$conn){
	$mail_template_ids = $mbfs = $attachment_file_ids =  [];
	$doc = new DOMDocument();

	$result = mysqli_query($conn, "SELECT mail_template_id,mail_template_content,attachment,timage_type FROM tb_core_mailcamp_template_list")->fetch_all(MYSQLI_ASSOC);

	foreach ($result as $row) {
		if($row['timage_type'] == 2);
	    	array_push($mail_template_ids, $row['mail_template_id']);

	   	foreach (json_decode($row['attachment'],true) as $att)
	   		if(!empty($att['file_id']))
	   			array_push($attachment_file_ids, $att['file_id']);

		@$doc->loadHTML($row['mail_template_content']);
		$tags = $doc->getElementsByTagName('img');
		foreach ($tags as $tag) {
		    $src = $tag->getAttribute('src');
		    $queries = getQueryValsFromURL($src);
		    if(!empty($queries['mbf']))
		    	array_push($mbfs, $queries['mbf']);
		}
	}

	$files = glob("uploads/timages/*.timg");	//tracker images - based on tid
	foreach ($files as $file)
	  if(!in_array(basename($file,'.timg'), $mail_template_ids))
	  	unlink($file);

	$files = glob("uploads/attachments/*.att");		//usaved attachments - based on attachment ids
	foreach ($files as $file)
	  if(!in_array(basename($file,'.att'), $attachment_file_ids))
	  	unlink($file);

	$files = glob("uploads/attachments/*.mbf");		//unsaved mail body files - based on img src url with mbd parameter
	foreach ($files as $file){
	    if(!in_array(explode("_", basename($file,'.mbf'))[1], $mbfs))	//eg: if 1611333260
	  		unlink($file);
	}

	//Delete junk payload file uploads
	$pl_ids = [];
	$result = mysqli_query($conn, "SELECT pl_id FROM tb_pl_list")->fetch_all(MYSQLI_ASSOC);
	foreach ($result as $row)
	  array_push($pl_ids, $row['pl_id']);

	$files = glob("payloads/uploads/*.pdata");
	foreach ($files as $file)
	  if(!in_array(basename($file,'.pdata'), $pl_ids))
	    unlink($file);

	//Delete junk sniperhost file uploads
	$file_ids = [];
	$result = mysqli_query($conn, "SELECT hf_id FROM tb_hf_list")->fetch_all(MYSQLI_ASSOC);
	foreach ($result as $row)
	  array_push($file_ids, $row['hf_id']);

	$files = glob("sniperhost/hf_files/*.hfile");
	foreach ($files as $file)
	  if(!in_array(basename($file,'.hfile'), $file_ids))
	    unlink($file);

	//Delete junk sniperhost text uploads
	$file_ids = [];
	$result = mysqli_query($conn, "SELECT ht_id FROM tb_ht_list")->fetch_all(MYSQLI_ASSOC);
	foreach ($result as $row)
	  array_push($file_ids, $row['ht_id']);

	$files = glob("sniperhost/ht_files/*.ptdata");
	foreach ($files as $file)
	  if(!(in_array(basename($file,'_in.ptdata'), $file_ids) || in_array(basename($file,'_out.ptdata'), $file_ids)))
	    unlink($file);

	//Delete public dashboard access table entries for deleted campaigns
	$file_ids = $arr_clearList = [];
	$result = mysqli_query($conn, "SELECT campaign_id FROM tb_core_mailcamp_list")->fetch_all(MYSQLI_ASSOC);
	foreach ($result as $row)
	  array_push($file_ids, $row['campaign_id']);

	$result = mysqli_query($conn, "SELECT tracker_id FROM tb_core_web_tracker_list")->fetch_all(MYSQLI_ASSOC);
	foreach ($result as $row)
	  array_push($file_ids, $row['tracker_id']);

	$result = mysqli_query($conn, "SELECT ctrl_ids FROM tb_access_ctrl")->fetch_all(MYSQLI_ASSOC);
	foreach ($result as $row){
	  $ctrl_ids = json_decode($row['ctrl_ids']);

	  if(!in_array($ctrl_ids[0], $file_ids))
	    deleteEntry($conn,json_encode($ctrl_ids));
	  else
	  if(count($ctrl_ids)==2){
	    if(!in_array($ctrl_ids[1], $file_ids))
	      deleteEntry($conn,json_encode($ctrl_ids));
	  }
	}
}

function deleteEntry(&$conn,$ctrl_ids){
	$stmt = $conn->prepare("DELETE FROM tb_access_ctrl WHERE ctrl_ids = ?");
	$stmt->bind_param("s", $ctrl_ids);
	$stmt->execute();
	$stmt->close();
}

//---------------Store Section Start------------------------------------
function getStoreList($conn, $type, $name){
	$resp = [];

	if($type == "mail_sender"){
		$stmt = $conn->prepare("SELECT name,info,content FROM tb_store WHERE type = ?");
		$stmt->bind_param("s", $type);
		$stmt->execute();
		$result = $stmt->get_result();
		$rows = $result->fetch_all(MYSQLI_ASSOC);
		foreach($rows as $i => $row)
			$resp[$row['name']] = ["info" => json_decode($row['info']), "content" => json_decode($row['content'])];
		echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
	}

	if($type == "mail_template"){
		if(empty($name)){
			$stmt = $conn->prepare("SELECT name,info FROM tb_store WHERE type = ?");
			$stmt->bind_param("s", $type);
			$stmt->execute();
			$result = $stmt->get_result();
			$result = $result->fetch_all(MYSQLI_ASSOC);
			foreach($result as $row)
				$resp[$row['name']] = json_decode($row['info']);
			echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
		}
		else{
			$stmt = $conn->prepare("SELECT content FROM tb_store WHERE type = ? AND name = ?");
			$stmt->bind_param("ss", $type, $name);
			$stmt->execute();
			$result = $stmt->get_result();
			if($result->num_rows != 0){
				$row = $result->fetch_assoc();
				echo $row['content'];
			}
		}
	}
}

//----------------Logs-----------
function getLogs($conn, &$POSTJ){
	$offset = htmlspecialchars($POSTJ['start']);
	$limit = htmlspecialchars($POSTJ['length']);
	$draw = htmlspecialchars($POSTJ['draw']);
	$search_value = '%'.htmlspecialchars($POSTJ['search']['value']).'%';
	$data = array();
	$columnIndex = htmlspecialchars($POSTJ['order'][0]['column']); // Column index
	$columnName = $POSTJ['columns'][$columnIndex]['data']; // Column name
	$columnSortOrder = $POSTJ['order'][0]['dir'] == 'asc'?'asc':'desc'; // asc or desc
	$totalRecords = 0;
	$arr_filtered = [];
	$DTime_info = getTimeInfo($conn);

	if (!in_array($columnName, ['id','username','log','ip','date']))	//should be db column name
	    $columnName = '';	
	if($columnName == '')
		$colSortString = '';
	else
		$colSortString = 'ORDER BY '.$columnName.' '.$columnSortOrder;

	$result = mysqli_query($conn, "SELECT COUNT(*) FROM tb_log");
	$row = mysqli_fetch_row($result);
	$totalRecords = $row[0];
	$totalRecords_with_filter = $totalRecords;//will be updated from below

	if(empty($search_value)){
		$stmt = $conn->prepare("SELECT username,log,date FROM tb_log ".$colSortString." LIMIT ? OFFSET ?");
		$stmt->bind_param("ss", $limit,$offset);
	}
	else{
		$stmt = $conn->prepare("SELECT COUNT(*) FROM tb_log WHERE username LIKE ? OR log LIKE ?");
		$stmt->bind_param("ss", $search_value,$search_value);	 
		$stmt->execute();
		$result = $stmt->get_result()->fetch_row();
		$totalRecords_with_filter = $result[0];

		$stmt = $conn->prepare("SELECT username,log,ip,date FROM tb_log WHERE username LIKE ? OR log LIKE ? OR ip LIKE ? ".$colSortString." LIMIT ? OFFSET ?");
		$stmt->bind_param("sssss", $search_value,$search_value,$search_value,$limit,$offset);
	}
	$stmt->execute();
	$result = $stmt->get_result();
	$rows = $result->fetch_all(MYSQLI_ASSOC);
	
	foreach ($rows as $i => $row)
		$rows[$i]['date'] = getInClientTime_FD($DTime_info,$row['date'],null,'d-m-Y h:i A');

	$stmt->close();
	$resp = array(
		"draw" => intval($draw),
		"recordsTotal" => intval($totalRecords),
		"recordsFiltered" => intval($totalRecords_with_filter),
		"data" => $rows
	);

	echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
}

function downloadLogs($conn,$file_format){
	$arr_odata=[];
	$DTime_info = getTimeInfo($conn);
	$file_name='SPLog-'.$GLOBALS['entry_time'];
	$selected_col = ['Username','Log','IP','Date Time'];

	$result = mysqli_query($conn, "SELECT username,log,ip,date FROM tb_log");
	if(mysqli_num_rows($result) > 0){
		$arr_odata=mysqli_fetch_all($result, MYSQLI_ASSOC);
		
		foreach ($arr_odata as $i => $row)
		    $arr_odata[$i]['date'] = getInClientTime_FD($DTime_info,$row['date'],null,'d-m-Y h:i A');

		if($file_format == 'csv'){
			$f = fopen('php://memory', 'w');
			// Phase 3.46 broader sweep: explicit fputcsv args + header_remove.
			fputcsv($f, $selected_col, ',', '"', '');
			foreach ($arr_odata as $line)
				fputcsv($f, $line, ',', '"', '');
			fseek($f, 0);
			header_remove('Content-Type');
		    header('Content-Type: text/csv');
		    header('Content-Disposition: attachment;filename="'.$file_name.'.csv"');
		    fpassthru($f);
		}
		elseif ($file_format == 'pdf') {
			$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
			$pdf->SetCreator(PDF_CREATOR);
			$pdf->SetAuthor(BRAND_PRODUCT_NAME);
			$pdf->SetTitle('Report data');
			$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
			$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
			$pdf->SetFont('helvetica', '', 8, '', true);
			$pdf->AddPage();

			$html_data=getHTMLData($arr_odata,$file_name,$selected_col,$selected_col,false);

			$pdf->writeHTML($html_data, true, false, true, false, '');
			$pdf->lastPage();
			$pdf->Output($file_name.'.pdf', 'I');
		}
		elseif ($file_format == 'html') {
			header('Content-Type: text/html');
		    header('Content-Disposition: attachment;filename="'.$file_name.'.html"');
			echo getHTMLData($arr_odata,$file_name,$selected_col,$selected_col);
		}
	}
}

function clearLog(&$conn){
    if ($conn->query('DELETE FROM tb_log') === TRUE) 
        echo(json_encode(['result' => 'success']));
    else
        echo json_encode(['result' => 'failed', 'error' => $conn->error]);

    $conn->close();
}
//-------------------------------------

// ==== Phase 3.25: TOTP 2FA management ====

function totpGetStatus($conn) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) { echo json_encode(['result' => 'failed', 'error' => 'No session']); return; }
    $stmt = $conn->prepare("SELECT totp_enabled FROM tb_main WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['result' => 'success', 'enabled' => !empty($row['totp_enabled'])]);
}

function totpBeginEnrollment($conn) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) { echo json_encode(['result' => 'failed', 'error' => 'No session']); return; }
    $secret = totp_generate_secret();
    $issuer = defined('BRAND_PRODUCT_NAME') ? BRAND_PRODUCT_NAME : 'TAPhish';
    $uri = totp_provisioning_uri($secret, $username, $issuer);
    $qrPng = generateQRBarCode('qr_b64', $uri);
    $qrB64 = $qrPng !== null ? base64_encode($qrPng) : '';
    // We do NOT persist the secret yet — only after totpConfirmEnrollment
    // verifies the operator can produce a valid code.
    echo json_encode([
        'result' => 'success',
        'secret' => $secret,
        'uri'    => $uri,
        'qr_b64' => $qrB64,
    ]);
}

function totpConfirmEnrollment($conn, $secret, $code) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) { echo json_encode(['result' => 'failed', 'error' => 'No session']); return; }
    $secret = (string) $secret;
    $code   = (string) $code;
    if (!preg_match('/^[A-Z2-7=]+$/i', $secret) || strlen($secret) < 16) {
        echo json_encode(['result' => 'failed', 'error' => 'Invalid secret payload']);
        return;
    }
    if (!totp_verify_code($secret, $code, time())) {
        echo json_encode(['result' => 'failed', 'error' => 'Code did not match — check your authenticator app clock and try again.']);
        return;
    }
    $stmt = $conn->prepare("UPDATE tb_main SET totp_secret = ?, totp_enabled = 1 WHERE username = ?");
    $stmt->bind_param('ss', $secret, $username);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['result' => 'failed', 'error' => 'Database update failed']);
        return;
    }
    $stmt->close();
    // Phase 3.31: generate 10 recovery codes on first enrollment so the
    // operator has something printed before their authenticator can fail
    // them. We display the plaintext exactly once in the response — the
    // DB only ever holds bcrypt hashes.
    $codes = totp_generate_recovery_codes(10);
    if (!totp_store_recovery_codes($conn, $username, $codes)) {
        logIt('2FA enabled but recovery code generation failed for ' . $username);
        echo json_encode([
            'result' => 'success',
            'recovery_codes' => [],
            'recovery_warning' => 'Recovery codes could not be generated. Open Settings → 2FA → Regenerate codes to retry.',
        ]);
        return;
    }
    logIt('2FA enabled for ' . $username);
    echo json_encode([
        'result' => 'success',
        'recovery_codes' => $codes,
    ]);
}

// Phase 3.31: replace any unused recovery codes with a fresh batch.
// Requires the current 2FA code so a stolen session can't quietly mint
// new bypass tokens. Returns the plaintext set exactly once.
function totpRegenerateRecoveryCodes($conn, $code) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) { echo json_encode(['result' => 'failed', 'error' => 'No session']); return; }
    $stmt = $conn->prepare("SELECT totp_secret, totp_enabled FROM tb_main WHERE username = ?");
    if ($stmt === false) {
        echo json_encode(['result' => 'failed', 'error' => 'Database error']);
        return;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['totp_enabled']) || empty($row['totp_secret'])) {
        echo json_encode(['result' => 'failed', 'error' => '2FA is not enabled on this account.']);
        return;
    }
    if (!totp_verify_code($row['totp_secret'], (string) $code, time())) {
        echo json_encode(['result' => 'failed', 'error' => 'Code did not match.']);
        return;
    }
    $codes = totp_generate_recovery_codes(10);
    if (!totp_store_recovery_codes($conn, $username, $codes)) {
        echo json_encode(['result' => 'failed', 'error' => 'Database update failed']);
        return;
    }
    logIt('2FA recovery codes regenerated for ' . $username);
    echo json_encode([
        'result' => 'success',
        'recovery_codes' => $codes,
    ]);
}

// Phase 3.31: surface remaining-unused count so the UI can nudge the
// operator before they've burned all of them.
function totpRecoveryStatus($conn) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) { echo json_encode(['result' => 'failed', 'error' => 'No session']); return; }
    $remaining = totp_remaining_recovery_codes($conn, $username);
    echo json_encode([
        'result'    => 'success',
        'remaining' => $remaining,
    ]);
}

function totpDisable($conn, $current_pwd, $code) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) { echo json_encode(['result' => 'failed', 'error' => 'No session']); return; }
    // Require BOTH the current password AND a valid 2FA code to disable —
    // a stolen session alone shouldn't be able to weaken the account.
    if (!isCurrentPwdCorrect($conn, $current_pwd)) {
        echo json_encode(['result' => 'failed', 'error' => 'Current password is wrong.']);
        return;
    }
    $stmt = $conn->prepare("SELECT totp_secret FROM tb_main WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['totp_secret']) || !totp_verify_code($row['totp_secret'], $code, time())) {
        echo json_encode(['result' => 'failed', 'error' => 'Code did not match.']);
        return;
    }
    $stmt = $conn->prepare("UPDATE tb_main SET totp_secret = NULL, totp_enabled = 0 WHERE username = ?");
    $stmt->bind_param('s', $username);
    if ($stmt->execute()) {
        // Phase 3.31: no point keeping recovery codes for a disabled
        // second factor — clear them so they can't be used to bypass
        // a re-enabled 2FA later with a fresh secret.
        totp_invalidate_recovery_codes($conn, $username);
        logIt('2FA disabled for ' . $username);
        echo json_encode(['result' => 'success']);
    } else {
        echo json_encode(['result' => 'failed', 'error' => 'Database update failed']);
    }
    $stmt->close();
}
?>