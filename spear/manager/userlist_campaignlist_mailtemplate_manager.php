<?php
require_once(dirname(__FILE__) . '/session_manager.php');
require_once(dirname(__FILE__) . '/common_functions.php');
require_once(dirname(__FILE__) . '/osint_hunter.php');
require_once(dirname(__FILE__) . '/osint_crt_sh.php');
require_once(dirname(__FILE__) . '/secret_at_rest.php');
require_once(dirname(__FILE__) . '/pretext_library.php');
require_once(dirname(__FILE__) . '/homoglyph.php');
require_once(dirname(__FILE__) . '/dmarc_lookup.php');
require_once(dirname(__FILE__) . '/engagement.php');
require_once(dirname(__FILE__) . '/mx_classify.php');
require_once(dirname(__FILE__) . '/web_fingerprint.php');
require_once(dirname(__FILE__) . '/toolset_checks.php');
require_once(dirname(__FILE__) . '/capture_alerting.php');
require_once(dirname(__FILE__,2) . '/libs/symfony/autoload.php');
require_once(dirname(__FILE__,2) . '/libs/qr_barcode/qrcode.php');
require_once(dirname(__FILE__,2) . '/libs/qr_barcode/barcode.php');
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
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
		if($POSTJ['action_type'] == "add_user_to_table")
			addUserToTable($conn, $POSTJ);
		if($POSTJ['action_type'] == "save_user_group")
			saveUserGroup($conn, $POSTJ['user_group_id'], $POSTJ['user_group_name']);
		if($POSTJ['action_type'] == "update_user")
			updateUser($conn,$POSTJ);
		if($POSTJ['action_type'] == "delete_user")
			deleteUser($conn, $POSTJ['user_group_id'], $POSTJ['uid']);
		if($POSTJ['action_type'] == "download_user")
			downloadUser($conn,$POSTJ['user_group_id']);
		if($POSTJ['action_type'] == "get_user_group_list")
			getUserGroupList($conn);
		if($POSTJ['action_type'] == "upload_user")
			uploadUserCVS($conn,$POSTJ);
		if($POSTJ['action_type'] == "get_user_group_from_group_Id_table")
			getUserGroupFromGroupIdTable($conn,$POSTJ);
		if($POSTJ['action_type'] == "delete_user_group_from_group_id")
			deleteUserGroupFromGroupId($conn,$POSTJ['user_group_id']);
		if($POSTJ['action_type'] == "make_copy_user_group")
			makeCopyUserGroup($conn, $POSTJ['user_group_id'], $POSTJ['new_user_group_id'], $POSTJ['new_user_group_name']);

		if($POSTJ['action_type'] == "save_mail_template")
			saveMailTemplate($conn,$POSTJ);
		if($POSTJ['action_type'] == "get_mail_template_list")
			getMailTemplateList($conn);
		if($POSTJ['action_type'] == "get_mail_template_from_template_id")
			getMailTemplateFromTemplateId($conn,$POSTJ['mail_template_id']);
		if($POSTJ['action_type'] == "delete_mail_template_from_template_id")
			deleteMailTemplateFromTemplateId($conn,$POSTJ['mail_template_id']);
		if($POSTJ['action_type'] == "make_copy_mail_template")
			makeCopyMailTemplate($conn, $POSTJ['mail_template_id'], $POSTJ['new_mail_template_id'], $POSTJ['new_mail_template_name']);

		// Phase 3.39: pretext library
		if($POSTJ['action_type'] == "list_pretexts") {
			echo json_encode(['result' => 'success', 'pretexts' => taphish_pretext_list($conn)]);
		}
		if($POSTJ['action_type'] == "clone_pretext_to_my_templates") {
			$new_id = taphish_pretext_clone_to_my_templates($conn, (int)($POSTJ['pretext_id'] ?? 0));
			echo json_encode(
				$new_id === null
					? ['result' => 'failed', 'error' => 'Could not clone pretext.']
					: ['result' => 'success', 'mail_template_id' => $new_id]
			);
		}

		// Phase 3.41: pre-engagement sender toolkit (homoglyph + DMARC).
		if($POSTJ['action_type'] == "homoglyph_candidates") {
			$domain = (string)($POSTJ['domain'] ?? '');
			$limit  = max(10, min(120, (int)($POSTJ['limit'] ?? 60)));
			echo json_encode([
				'result'     => 'success',
				'domain'     => $domain,
				'candidates' => taphish_homoglyph_candidates($domain, $limit),
			]);
		}
		if($POSTJ['action_type'] == "email_posture_lookup") {
			$domain = (string)($POSTJ['domain'] ?? '');
			$result = taphish_lookup_email_posture($domain);
			echo json_encode(['result' => 'success', 'posture' => $result]);
		}

		// Phase 3.43a: engagement metadata (Quick-Start Wizard step 1).
		if($POSTJ['action_type'] == "save_engagement") {
			$payload = is_array($POSTJ['payload'] ?? null) ? $POSTJ['payload'] : [];
			$v = taphish_engagement_validate_input($payload);
			if (!$v['ok']) {
				echo json_encode(['result' => 'failed', 'errors' => $v['errors']]);
			} else {
				$createdBy = (string)($_SESSION['username'] ?? '');
				$id = taphish_engagement_insert($conn, $v['normalized'], $createdBy);
				if ($id === null) {
					echo json_encode(['result' => 'failed', 'error' => 'Could not save engagement.']);
				} else {
					logIt('Engagement created: ' . $v['normalized']['name']);
					echo json_encode([
						'result'        => 'success',
						'engagement_id' => $id,
						'slug'          => $v['normalized']['slug'],
					]);
				}
			}
		}
		if($POSTJ['action_type'] == "list_engagements") {
			echo json_encode([
				'result' => 'success',
				'engagements' => taphish_engagement_list($conn),
			]);
		}

		// Phase 3.43b: OSINT pre-check fan-out. Each action runs one
		// helper; the wizard JS issues them in parallel and renders into
		// the OSINT card lanes.
		if($POSTJ['action_type'] == "mx_classify_domain") {
			$domain = (string)($POSTJ['domain'] ?? '');
			echo json_encode([
				'result' => 'success',
				'mx'     => taphish_mx_classify_domain($domain),
			]);
		}
		if($POSTJ['action_type'] == "web_fingerprint") {
			$domain = (string)($POSTJ['domain'] ?? '');
			echo json_encode([
				'result' => 'success',
				'web'    => taphish_web_fingerprint($domain),
			]);
		}
		// Phase 3.43h: Toolset Checker.
		if($POSTJ['action_type'] == "run_toolset_checks") {
			$webhook = '';
			if (function_exists('taphish_get_webhook_url')) {
				$webhook = (string) taphish_get_webhook_url($conn);
			}
			$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
			$status_url = $host ? ('https://' . $host . '/status') : '';
			$writable = [
				dirname(__FILE__, 2) . '/uploads',
				dirname(__FILE__, 2) . '/sniperhost/cloned',
			];
			$senderDomain = trim((string)($POSTJ['sender_domain'] ?? ''));
			$opts = [
				'sender_domain' => $senderDomain,
				'webhook_url'   => $webhook,
				'status_url'    => $status_url,
				'writable_dirs' => $writable,
			];
			echo json_encode([
				'result' => 'success',
				'report' => taphish_toolset_run($opts),
			]);
		}
		// Phase 3.43c: pretext picker filtered by detected tech stack.
		if($POSTJ['action_type'] == "list_pretexts_ranked") {
			$cats = $POSTJ['categories'] ?? [];
			if (!is_array($cats)) $cats = [];
			$cats = array_values(array_filter(array_map('strval', $cats)));
			$flat = taphish_pretext_list_flat($conn);
			$limit = max(3, min(20, (int)($POSTJ['limit'] ?? 8)));
			$ranked = array_slice(
				taphish_pretext_rank_for_categories($flat, $cats),
				0,
				$limit
			);
			echo json_encode([
				'result' => 'success',
				'preferred_categories' => $cats,
				'pretexts' => $ranked,
			]);
		}
		if($POSTJ['action_type'] == "upload_tracker_image")
			uploadTrackerImage($conn,$POSTJ);
		if($POSTJ['action_type'] == "upload_attachments")
			uploadAttachment($conn,$POSTJ);
		if($POSTJ['action_type'] == "upload_mail_body_files")
			uploadMailBodyFiles($conn,$POSTJ);

		if($POSTJ['action_type'] == "save_sender_list")
			saveSenderList($conn, $POSTJ);
		if($POSTJ['action_type'] == "get_sender_list")
			getSenderList($conn);	
		if($POSTJ['action_type'] == "get_sender_from_sender_list_id")
			getSenderFromSenderListId($conn,$POSTJ['sender_list_id']);	
		if($POSTJ['action_type'] == "delete_mail_sender_list_from_list_id")
			deleteMailSenderListFromSenderId($conn,$POSTJ['sender_list_id']);
		if($POSTJ['action_type'] == "make_copy_sender_list")
			makeCopyMailSenderList($conn,$POSTJ['sender_list_id'],$POSTJ['new_sender_list_id'],$POSTJ['new_sender_list_name']);
		if($POSTJ['action_type'] == "verify_mailbox_access")
			verifyMailboxAccess($conn,$POSTJ);

		if($POSTJ['action_type'] == "send_test_mail_verification")
			sendTestMailVerification($conn,$POSTJ);
		if($POSTJ['action_type'] == "send_test_mail_sample")
			sendTestMailSample($conn,$POSTJ);
		if($POSTJ['action_type'] == "osint_hunter_search") {
			$domain = (string) ($POSTJ['domain'] ?? '');
			$apiKey = (string) ($POSTJ['api_key'] ?? '');
			$limit  = (int) ($POSTJ['limit'] ?? 25);
			$result = osint_hunter_domain_search($domain, $apiKey, $limit);
			echo json_encode(['result' => $result['ok'] ? 'success' : 'failed'] + $result);
		}
		if($POSTJ['action_type'] == "osint_hunter_email_finder") {
			$domain = (string) ($POSTJ['domain'] ?? '');
			$first  = (string) ($POSTJ['first_name'] ?? '');
			$last   = (string) ($POSTJ['last_name'] ?? '');
			$apiKey = (string) ($POSTJ['api_key'] ?? '');
			$result = osint_hunter_email_finder($domain, $first, $last, $apiKey);
			echo json_encode(['result' => $result['ok'] ? 'success' : 'failed'] + $result);
		}
		if($POSTJ['action_type'] == "osint_crt_sh_subdomains") {
			$domain = (string) ($POSTJ['domain'] ?? '');
			$result = osint_crt_sh_subdomains($domain);
			echo json_encode(['result' => $result['ok'] ? 'success' : 'failed'] + $result);
		}
	}
}

//-----------------------------
function addUserToTable($conn, &$POSTJ){
	$user_group_id = $POSTJ['user_group_id'];
	$user_group_name = $POSTJ['user_group_name'];
	if(empty($user_group_name))
		die(json_encode(['result' => 'failed', 'error' => 'Error adding user!']));			

	$row = getUserGroupFromGroupId($conn, $user_group_id);
	// Phase 3.38: user_data may hold a legacy plaintext JSON blob OR an
	// enc1: at-rest envelope. recipient_data_unseal() returns the
	// plaintext JSON in both cases (or null if a missing key blocks
	// decrypt — we treat that as "empty list" rather than blow up,
	// matching the previous behavior).
	if(empty($row) || empty($row["user_data"]))
		$user_data =[];
	else
		$user_data = json_decode((string)recipient_data_unseal($row["user_data"]),true) ?? [];

	$uid = getRandomStr(10);
	array_push($user_data,['uid'=>$uid, 'fname'=>$POSTJ['fname'], 'lname'=>$POSTJ['lname'], 'email'=>$POSTJ['email'], 'notes'=>$POSTJ['notes']]);
	$user_data_sealed = recipient_data_seal(json_encode(array_unique($user_data, SORT_REGULAR)));

	if(checkAnIDExist($conn,$user_group_id,'user_group_id','tb_core_mailcamp_user_group')){
		$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_group_name=?, user_data=? WHERE user_group_id=?");
		$stmt->bind_param('sss', $user_group_name,$user_data_sealed,$user_group_id);
	}
	else{
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,user_data,date) VALUES(?,?,?,?)");
		$stmt->bind_param('ssss', $user_group_id,$user_group_name,$user_data_sealed,$GLOBALS['entry_time']);
	}

	if($stmt->execute() === TRUE){
		echo(json_encode(['result' => 'success']));	
	}
	else 
		echo(json_encode(['result' => 'failed', 'error' => 'Error adding user!']));			
}

function saveUserGroup($conn, $user_group_id, $user_group_name){
	// Phase 3.35: capture existence before write so create/update verb
	// is accurate in the audit-log entry.
	$is_update = checkAnIDExist($conn,$user_group_id,'user_group_id','tb_core_mailcamp_user_group');
	if($is_update){
		$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_group_name=? WHERE user_group_id=?");
		$stmt->bind_param('ss', $user_group_name,$user_group_id);
	}
	else{
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,date) VALUES(?,?,?)");
		$stmt->bind_param('sss', $user_group_id,$user_group_name,$GLOBALS['entry_time']);
	}

	if ($stmt->execute() === TRUE) {
		logIt('Recipient list ' . ($is_update ? 'updated' : 'created') . ': ' . $user_group_name);
		echo(json_encode(['result' => 'success']));
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Error saving data!']));
}

function updateUser($conn, &$POSTJ){
	$user_group_id = $POSTJ['user_group_id'];
	$uid = $POSTJ['uid'];

	$row = getUserGroupFromGroupId($conn, $user_group_id);

	if(!empty($row)){
		// Phase 3.38: unseal before edit, re-seal before write.
		$user_data = json_decode((string)recipient_data_unseal($row["user_data"]),true) ?? [];

		$index = array_search($uid, array_column($user_data, 'uid'));
		if($index !== false ){	//returns false if not found
			$user_data[$index]= ['uid'=>$uid, 'fname'=>$POSTJ['fname'], 'lname'=>$POSTJ['lname'], 'email'=>$POSTJ['email'], 'notes'=>$POSTJ['notes']];
			$user_data_sealed = recipient_data_seal(json_encode($user_data));
			$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_data=? WHERE user_group_id=?");
			$stmt->bind_param('ss', $user_data_sealed,$user_group_id);
			if($stmt->execute() === TRUE)
				echo(json_encode(['result' => 'success']));
			else
				echo(json_encode(['result' => 'failed', 'error' => 'Error updating row!']));
		}
		else
			echo(json_encode(['result' => 'failed', 'error' => 'Error updating row. User not found!']));
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Error updating row. User group not found!']));
}

function deleteUser($conn, $user_group_id, $uid){
	$stmt = $conn->prepare("SELECT user_data FROM tb_core_mailcamp_user_group WHERE user_group_id = ?");
	$stmt->bind_param("s", $user_group_id);
	$stmt->execute();
	$result = $stmt->get_result();
	if($result->num_rows != 0){
		$row = $result->fetch_assoc();
		// Phase 3.38: unseal before delete-by-uid, re-seal before write.
		$user_data = json_decode((string)recipient_data_unseal($row["user_data"]),true) ?? [];

		$index = array_search($uid, array_column($user_data, 'uid'));
		if($index !== false ){	//returns false if not found
			unset($user_data[$index]);
			$user_data_sealed = recipient_data_seal(json_encode($user_data));
			$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_data=? WHERE user_group_id=?");
			$stmt->bind_param('ss', $user_data_sealed,$user_group_id);
			if($stmt->execute() === TRUE)
				echo(json_encode(['result' => 'success']));
			else
				echo(json_encode(['result' => 'failed', 'error' => 'Error deleting row!']));
		}else
			echo(json_encode(['result' => 'failed', 'error' => 'Error deleting row. User not found!']));
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Error deleting row. User group not found!']));	
}

function downloadUser($conn, $user_group_id){
	$stmt = $conn->prepare("SELECT user_data,user_group_name FROM tb_core_mailcamp_user_group WHERE user_group_id = ?");
	$stmt->bind_param("s", $user_group_id);
	$stmt->execute();
	$result = $stmt->get_result();
	if($result->num_rows != 0){
		$row = $result->fetch_assoc();
		// Phase 3.38: unseal before serializing to CSV.
		$user_data = json_decode((string)recipient_data_unseal($row["user_data"]),true) ?? [];

		$f = fopen('php://memory', 'w');
		fputcsv($f, ['First Name', 'Last Name', 'Email', 'Notes'], ',');

	    foreach ($user_data as $line) {
	    	unset($line['uid']);	//remove uid field
	        fputcsv($f, $line, ',');
	    }

	    fseek($f, 0);
	    header('Content-Type: text/csv');
	    header('Content-Disposition: attachment; filename='.$row['user_group_name']);
	    fpassthru($f);
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Error updating row. User group not found!']));	
}

function getUserGroupList($conn){
	$resp = [];
	$DTime_info = getTimeInfo($conn);
	// Phase 3.38: SELECT user_data instead of JSON_LENGTH(user_data) —
	// once the column holds an enc1: envelope, server-side JSON parsing
	// is impossible. Compute count client-side after recipient_data_unseal().
	$result = mysqli_query($conn, "SELECT user_group_id,user_group_name,user_data,date FROM tb_core_mailcamp_user_group");
	if(mysqli_num_rows($result) > 0){
		foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row){
			$plain = recipient_data_unseal($row["user_data"]);
			$parsed = $plain === null ? [] : (json_decode((string)$plain, true) ?? []);
			$row["user_count"] = is_array($parsed) ? count($parsed) : 0;
			// Drop the raw user_data from the list payload — callers
			// of getUserGroupList don't need the recipient details, and
			// shipping plaintext PII over an AJAX response we don't have
			// to is the wrong default.
			unset($row["user_data"]);
			$row["date"] = getInClientTime_FD($DTime_info,$row['date'],null,'d-m-Y h:i A');
			array_push($resp,$row);
		}
		echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
	}
	else
		echo json_encode(['error' => 'No data']);
}

function uploadUserCVS($conn, &$POSTJ){
	$user_group_id = $POSTJ['user_group_id'];	
	$user_group_name = $POSTJ['user_group_name'];
	$user_data = explode("\n", $POSTJ['user_data']);
	array_shift($user_data);	//removes column heading 
	$user_data = array_map('trim', $user_data);	//trim array strings
	$user_data = array_filter(($user_data));	//removes empty array
	$arr_users=[];

	foreach ($user_data as $user) {
		$user = explode(",", $user);
		$uid = getRandomStr(10);

		if(isValidEmail($user[1]))
	    	array_push($arr_users,['uid'=>$uid, 'fname'=>$user[0], 'lname'=>null, 'email'=>$user[1], 'notes'=>$user[2]]);
    	elseif(isValidEmail($user[2]))
	    	array_push($arr_users,['uid'=>$uid, 'fname'=>$user[0], 'lname'=>$user[1], 'email'=>$user[2], 'notes'=>$user[3]]);
    	else
    		die(json_encode(['result' => 'failed', 'error' => 'Import failed. Invalid email at '. $user[2]]));
	}

	$row = getUserGroupFromGroupId($conn, $user_group_id);
	// Phase 3.38: unseal existing rows before merge, re-seal before write.
	if(!empty($row['user_data']))
		$old_user_data = json_decode((string)recipient_data_unseal($row["user_data"]),true) ?? [];
	else
		$old_user_data = [];

	$user_data = array_merge($old_user_data,$arr_users);
	$user_data_sealed = recipient_data_seal(json_encode($user_data));

	if(checkAnIDExist($conn,$user_group_id,'user_group_id','tb_core_mailcamp_user_group')){
		$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_group_name=?, user_data=? WHERE user_group_id=?");
		$stmt->bind_param('sss', $user_group_name,$user_data_sealed,$user_group_id);
	}
	else{
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,user_data,date) VALUES(?,?,?,?)");
		$stmt->bind_param('ssss', $user_group_id,$user_group_name,$user_data_sealed,$GLOBALS['entry_time']);
	}

	if($stmt->execute() === TRUE){
		logIt('Recipient list imported: ' . $user_group_name . ' (' . count($arr_users) . ' rows)');
		echo(json_encode(['result' => 'success']));
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Error importing user data!']));
}

function getUserGroupFromGroupIdTable($conn,&$POSTJ){
	$offset = htmlspecialchars($POSTJ['start']);
	$limit = htmlspecialchars($POSTJ['length']);
	$draw = htmlspecialchars($POSTJ['draw']);
	$search_value = htmlspecialchars($POSTJ['search']['value']);
	$data = array();
	$columnSortOrder = $POSTJ['order'][0]['dir'] == 'asc'?'asc':'desc'; // asc or desc
	$totalRecords = 0;
	$user_group_id = $POSTJ['user_group_id'];

	if(empty($search_value))
		$totalRecords_with_filter = $totalRecords;
	else
		$totalRecords_with_filter = 0;	//will be updated from below

	$arr_filtered=[];
	$row = getUserGroupFromGroupId($conn, $user_group_id);

	if(!empty($row)){
		// Phase 3.38: unseal before search + DataTables paging.
		$user_data = json_decode((string)recipient_data_unseal($row["user_data"]),true) ?? [];
		foreach ($user_data as $item){
		    $m_array = preg_grep('/.*'.$search_value.'.*/', $item);
		    if(!empty($m_array))
		    	array_push($arr_filtered, $item);
		}

		$totalRecords = empty($user_data)?0:sizeof($user_data);
		$totalRecords_with_filter = sizeof($arr_filtered);
		$resp = array(
		  "draw" => intval($draw),
		  "recordsTotal" => intval($totalRecords),
		  "recordsFiltered" => intval($totalRecords_with_filter),
		  "data" => array_slice($arr_filtered, $offset, $limit)
		);

		$resp['user_group_name'] = $row['user_group_name'];
		echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
	}		
	else
		echo json_encode(['error' => 'No data']);	
}

function deleteUserGroupFromGroupId($conn,$user_group_id){
	// Phase 3.35: capture name before delete for the audit-log entry.
	$row = getUserGroupFromGroupId($conn, $user_group_id);
	$user_group_name = $row['user_group_name'] ?? $user_group_id;
	$stmt = $conn->prepare("DELETE FROM tb_core_mailcamp_user_group WHERE user_group_id = ?");
	$stmt->bind_param("s", $user_group_id);
	$stmt->execute();
	if($stmt->affected_rows != 0){
		logIt('Recipient list deleted: ' . $user_group_name);
		echo json_encode(['result' => 'success']);
	}
	else
		echo json_encode(['result' => 'failed', 'error' => 'User group does not exist']);
	$stmt->close();
}

function makeCopyUserGroup($conn, $old_user_group_id, $new_user_group_id, $new_user_group_name){
	$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group (user_group_id,user_group_name,user_data,date) SELECT ?, ?,user_data,? FROM tb_core_mailcamp_user_group WHERE user_group_id=?");
	$stmt->bind_param("ssss", $new_user_group_id, $new_user_group_name, $GLOBALS['entry_time'], $old_user_group_id);

	if($stmt->execute() === TRUE){
		logIt('Recipient list copied: ' . $new_user_group_name);
		echo(json_encode(['result' => 'success']));
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => 'Error making copy!']));
	$stmt->close();
}

function getUserGroupFromGroupId($conn, $user_group_id){
	$stmt = $conn->prepare("SELECT * FROM tb_core_mailcamp_user_group WHERE user_group_id = ?");
	$stmt->bind_param("s", $user_group_id);
	$stmt->execute();
	$result = $stmt->get_result();
	if($result->num_rows != 0)
		return $result->fetch_assoc();
	return [];
}
//---------------------------------------Email Template Section --------------------------------

function saveMailTemplate($conn,&$POSTJ){
	$mail_template_id = $POSTJ['mail_template_id'];
	if($mail_template_id == '')
		$mail_template_id = null;

	$mail_template_name = $POSTJ['mail_template_name'];
	$mail_template_subject = $POSTJ['mail_template_subject'];
	$mail_template_content = $POSTJ['mail_template_content'];
	$timage_type = $POSTJ['timage_type'];
	$attachments = json_encode($POSTJ['attachments']);
	$mail_content_type = $POSTJ['mail_content_type'];

	$is_update = checkAnIDExist($conn,$mail_template_id,'mail_template_id','tb_core_mailcamp_template_list');
	if($is_update){
		$stmt = $conn->prepare("UPDATE tb_core_mailcamp_template_list SET mail_template_name=?, mail_template_subject=?, mail_template_content=?, timage_type=?, mail_content_type=?, attachment=? WHERE mail_template_id=?");
		$stmt->bind_param('sssssss', $mail_template_name,$mail_template_subject, $mail_template_content,$timage_type,$mail_content_type,$attachments,$mail_template_id);
	}
	else{
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_template_list(mail_template_id, mail_template_name, mail_template_subject, mail_template_content, timage_type, mail_content_type, attachment, date) VALUES(?,?,?,?,?,?,?,?)");
		$stmt->bind_param('ssssssss', $mail_template_id,$mail_template_name,$mail_template_subject,$mail_template_content,$timage_type,$mail_content_type,$attachments,$GLOBALS['entry_time']);
	}

	if ($stmt->execute() === TRUE){
		logIt('Template ' . ($is_update ? 'updated' : 'created') . ': ' . $mail_template_name);
		echo(json_encode(['result' => 'success']));
	}
	else
		echo(json_encode(['result' => 'failed', 'error' => $stmt->error]));
}

function getMailTemplateList($conn){
	$resp = [];
	$DTime_info = getTimeInfo($conn);
	$result = mysqli_query($conn, "SELECT mail_template_id, mail_template_name, LEFT(mail_template_subject , 50) mail_template_subject, LEFT(mail_template_content , 50) mail_template_content,attachment,date FROM tb_core_mailcamp_template_list");

	if(mysqli_num_rows($result) > 0){
		foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row){
			$row["attachment"] = json_decode($row["attachment"]);	//avoid double json encoding
			$row["date"] = getInClientTime_FD($DTime_info,$row['date'],null,'d-m-Y h:i A');
        	array_push($resp,$row);
		}
		echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
	}
	else
		echo json_encode(['error' => 'No data']);	
	$result->close();
}

function getMailTemplateFromTemplateId($conn, $mail_template_id){
	$stmt = $conn->prepare("SELECT * FROM tb_core_mailcamp_template_list WHERE mail_template_id = ?");
	$stmt->bind_param("s", $mail_template_id);
	$stmt->execute();
	$result = $stmt->get_result();
	if($result->num_rows != 0){
		$row = $result->fetch_assoc() ;
		$row['attachment'] = json_decode($row['attachment']);
		echo json_encode($row, JSON_INVALID_UTF8_IGNORE) ;
	}
	else
		echo json_encode(['error' => 'No data']);				
	$stmt->close();
}

function deleteMailTemplateFromTemplateId($conn,$mail_template_id){
	// Phase 3.35: name lookup before delete for audit-log clarity.
	$name_stmt = $conn->prepare("SELECT mail_template_name FROM tb_core_mailcamp_template_list WHERE mail_template_id = ?");
	$template_name = $mail_template_id;
	if ($name_stmt !== false) {
		$name_stmt->bind_param("s", $mail_template_id);
		$name_stmt->execute();
		$row = $name_stmt->get_result()->fetch_assoc();
		if ($row && !empty($row['mail_template_name'])) $template_name = $row['mail_template_name'];
		$name_stmt->close();
	}
	$stmt = $conn->prepare("DELETE FROM tb_core_mailcamp_template_list WHERE mail_template_id = ?");
	$stmt->bind_param("s", $mail_template_id);
	$stmt->execute();
	if($stmt->affected_rows != 0){
		logIt('Template deleted: ' . $template_name);
		echo json_encode(['result' => 'success']);
	}
	else
		echo json_encode(['result' => 'failed', 'error' => 'Mail template does not exist']);
	$stmt->close();
}

function makeCopyMailTemplate($conn, $old_mail_template_id, $new_mail_template_id, $new_mail_template_name){
	$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_template_list (mail_template_id,mail_template_name,mail_template_subject,mail_template_content,timage_type,mail_content_type,attachment,date) SELECT ?, ?, mail_template_subject,mail_template_content,timage_type,mail_content_type,attachment,? FROM tb_core_mailcamp_template_list WHERE mail_template_id=?");
	$stmt->bind_param("ssss", $new_mail_template_id, $new_mail_template_name, $GLOBALS['entry_time'], $old_mail_template_id);

	if ($stmt->execute() === TRUE){
		logIt('Template copied: ' . $new_mail_template_name);
		echo json_encode(['result' => 'success']);
	}
	else
		echo json_encode(['result' => 'failed', 'error' => $stmt->error]);
	$stmt->close();
}

function uploadTrackerImage($conn,&$POSTJ){
	$mail_template_id = $POSTJ['mail_template_id'];
	$file_name = filter_var($POSTJ['file_name'], FILTER_SANITIZE_STRING);
	$file_b64 = explode(',', $POSTJ['file_b64'])[1];
	$binary_data = base64_decode($file_b64);

	$target_file = dirname(__FILE__,2).'/uploads/timages/'.$mail_template_id.'.timg';
	if(getimagesizefromstring($binary_data)){
        try{
        	file_put_contents($target_file,$binary_data);
        	echo(json_encode(['result' => 'success']));	
        }catch(Exception $e) {
			echo(json_encode(['result' => 'failed', 'error' => $e->getMessage()]));	
		}        	
    }
    else
    	echo(json_encode(['result' => 'failed', 'error' => 'Invalid file']));	
}

function uploadAttachment($conn,&$POSTJ){
	$mail_template_id = $POSTJ['mail_template_id'];
	$file_name = filter_var($POSTJ['file_name'], FILTER_SANITIZE_STRING);
	$file_b64 = explode(',', $POSTJ['file_b64'])[1];
	$binary_data = base64_decode($file_b64);
	$file_id = $mail_template_id.'_'.time();

	$target_file = dirname(__FILE__,2).'/uploads/attachments/'.$file_id.'.att';

	if (!is_dir(dirname(__FILE__,2).'/uploads/attachments/')) 
		die(json_encode(['result' => 'failed', 'error' => 'Directory spear/uploads/attachments/ does not exist']));
	if (!is_writable(dirname(__FILE__,2).'/uploads/attachments/')) 
		die(json_encode(['result' => 'failed', 'error' => 'Directory spear/uploads/attachments/ has no write permission']));

	try{
    	if(file_put_contents($target_file,$binary_data) || file_exists($target_file))	//if 0 size file failed, check if they exist (written)
    		echo(json_encode(['result' => 'success', 'file_id' => $file_id]));	
    	else
			echo(json_encode(['result' => 'failed', 'error' => 'File upload failed!']));	
    }catch(Exception $e) {
		echo(json_encode(['result' => 'failed', 'error' => $e->getMessage()]));	
	}       
}

function uploadMailBodyFiles($conn,&$POSTJ){
	$mail_template_id = $POSTJ['mail_template_id'];
	$file_name = filter_var($POSTJ['file_name'], FILTER_SANITIZE_STRING);
	$file_b64 = explode(',', $POSTJ['file_b64'])[1];
	$binary_data = base64_decode($file_b64);
	$file_id_part = time();
	$file_id = $mail_template_id.'_'.$file_id_part;

	$target_file = dirname(__FILE__,2).'/uploads/attachments/'.$file_id.'.mbf';

	if (!is_dir(dirname(__FILE__,2).'/uploads/attachments/')) 
		die(json_encode(['result' => 'failed', 'error' => 'Directory spear/uploads/attachments/ does not exist']));
	if (!is_writable(dirname(__FILE__,2).'/uploads/attachments/')) 
		die(json_encode(['result' => 'failed', 'error' => 'Directory spear/uploads/attachments/ has no write permission']));

	try{
    	if(file_put_contents($target_file,$binary_data) || file_exists($target_file))	//if 0 size file failed, check if they exist (written)
    		echo(json_encode(['result' => 'success', 'file_id' => $file_id, "mbf" => $file_id_part]));	
    	else
    		echo(json_encode(['result' => 'failed', 'error' => $e->getMessage()]));	
    }catch(Exception $e) {
		echo(json_encode(['result' => 'failed', 'error' =>'File upload failed!']));	
	}       
}

//---------------------------------------Sender List Section --------------------------------
function saveSenderList($conn, &$POSTJ){
	$sender_list_id = $POSTJ['sender_list_id'];
	$sender_list_mail_sender_name = $POSTJ['sender_list_mail_sender_name'];
	$sender_list_mail_sender_SMTP_server = $POSTJ['sender_list_mail_sender_SMTP_server'];
	$sender_list_mail_sender_from = $POSTJ['sender_list_mail_sender_from'];
	$sender_list_mail_sender_acc_username = $POSTJ['sender_list_mail_sender_acc_username'];
	$sender_list_mail_sender_acc_pwd = $POSTJ['sender_list_mail_sender_acc_pwd'];
	// Phase 3.27: seal the SMTP password before storing.
	if ($sender_list_mail_sender_acc_pwd !== '') {
		$sender_list_mail_sender_acc_pwd = mail_sender_seal_pwd($sender_list_mail_sender_acc_pwd);
	}
	$auto_mailbox = $POSTJ['cb_auto_mailbox'];
	$mail_sender_mailbox = $POSTJ['mail_sender_mailbox'];
	$sender_list_cust_headers = json_encode($POSTJ['sender_list_cust_headers']); 
	$dsn_type = $POSTJ['dsn_type'];

	if(checkAnIDExist($conn,$sender_list_id,'sender_list_id','tb_core_mailcamp_sender_list')){
		if($sender_list_mail_sender_acc_pwd != ''){	//new sender acc pwd
			$stmt = $conn->prepare("UPDATE tb_core_mailcamp_sender_list SET sender_name=?, sender_SMTP_server=?, sender_from=?, sender_acc_username=?, sender_acc_pwd=?, auto_mailbox=?, sender_mailbox=?, cust_headers=?, dsn_type=? WHERE sender_list_id=?");
			$stmt->bind_param('ssssssssss', $sender_list_mail_sender_name,$sender_list_mail_sender_SMTP_server,$sender_list_mail_sender_from,$sender_list_mail_sender_acc_username,$sender_list_mail_sender_acc_pwd,$auto_mailbox,$mail_sender_mailbox,$sender_list_cust_headers,$dsn_type,$sender_list_id);
		}
		else{	//sender acc pwd has no change
			$stmt = $conn->prepare("UPDATE tb_core_mailcamp_sender_list SET sender_name=?, sender_SMTP_server=?, sender_from=?, sender_acc_username=?, auto_mailbox=?, sender_mailbox=?, cust_headers=?, dsn_type=? WHERE sender_list_id=?");
			$stmt->bind_param('sssssssss', $sender_list_mail_sender_name,$sender_list_mail_sender_SMTP_server,$sender_list_mail_sender_from,$sender_list_mail_sender_acc_username,$auto_mailbox,$mail_sender_mailbox,$sender_list_cust_headers,$dsn_type,$sender_list_id);
		}
	}
	else{
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_sender_list(sender_list_id,sender_name,sender_SMTP_server,sender_from,sender_acc_username,sender_acc_pwd,auto_mailbox,sender_mailbox,cust_headers,dsn_type,date) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
		$stmt->bind_param('sssssssssss', $sender_list_id,$sender_list_mail_sender_name,$sender_list_mail_sender_SMTP_server,$sender_list_mail_sender_from,$sender_list_mail_sender_acc_username,$sender_list_mail_sender_acc_pwd,$auto_mailbox,$mail_sender_mailbox,$sender_list_cust_headers,$dsn_type,$GLOBALS['entry_time']);
	}
	
	if ($stmt->execute() === TRUE)
		echo json_encode(['result' => 'success']);
	else 
		echo json_encode(['result' => 'failed']);
}

function getSenderList($conn){
	$resp = [];
	$DTime_info = getTimeInfo($conn);
	$result = mysqli_query($conn, "SELECT sender_list_id,sender_name,sender_SMTP_server,sender_from,sender_acc_username,sender_mailbox,cust_headers,dsn_type,date FROM tb_core_mailcamp_sender_list");
	if(mysqli_num_rows($result) > 0){
		foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row){
			$row["cust_headers"] = json_decode($row["cust_headers"]);	//avoid double json encoding
			$row["date"] = getInClientTime_FD($DTime_info,$row['date'],null,'d-m-Y h:i A');
        	array_push($resp,$row);
		}
		echo json_encode($resp, JSON_INVALID_UTF8_IGNORE);
	}
	else
		echo json_encode(['error' => 'No data']);	
	$result->close();
}

function getSenderFromSenderListId($conn, $sender_list_id){
	$stmt = $conn->prepare("SELECT sender_name,sender_SMTP_server,sender_from,sender_acc_username,auto_mailbox,sender_mailbox,cust_headers,dsn_type FROM tb_core_mailcamp_sender_list WHERE sender_list_id = ?");
	$stmt->bind_param("s", $sender_list_id);
	$stmt->execute();
	$result = $stmt->get_result();
	if($result->num_rows > 0){
		$row = $result->fetch_assoc() ;
		$row["cust_headers"] = json_decode($row["cust_headers"]);	//avoid double json encoding
		echo json_encode($row, JSON_INVALID_UTF8_IGNORE) ;
	}			
	else
		echo json_encode(['error' => 'No data']);	
	$stmt->close();
}

function deleteMailSenderListFromSenderId($conn, $sender_list_id){	
	$stmt = $conn->prepare("DELETE FROM tb_core_mailcamp_sender_list WHERE sender_list_id = ?");
	$stmt->bind_param("s", $sender_list_id);
	$stmt->execute();
	if($stmt->affected_rows != 0)
		echo json_encode(['result' => 'success']);	
	else
		echo json_encode(['result' => 'failed', 'error' => 'Error deleting sender!']);	
	$stmt->close();
}

function makeCopyMailSenderList($conn, $old_sender_list_id, $new_sender_list_id, $new_sender_list_name){
	$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_sender_list (sender_list_id,sender_name,sender_SMTP_server,sender_from,sender_acc_username,sender_acc_pwd,auto_mailbox,sender_mailbox,cust_headers,dsn_type,date) SELECT ?, ?, sender_SMTP_server,sender_from,sender_acc_username,sender_acc_pwd,auto_mailbox,sender_mailbox,cust_headers,dsn_type,? FROM tb_core_mailcamp_sender_list WHERE sender_list_id=?");
	$stmt->bind_param("ssss", $new_sender_list_id, $new_sender_list_name, $GLOBALS['entry_time'], $old_sender_list_id);
	
	if ($stmt->execute() === TRUE)
		echo json_encode(['result' => 'success']);	
	else
		echo json_encode(['result' => 'failed', 'error' => $stmt->error]);	
	$stmt->close();
}

function verifyMailboxAccess($conn, $POSTJ){
	$sender_list_id = $POSTJ['sender_list_id'];
	$sender_username = $POSTJ['mail_sender_acc_username'];
	$sender_pwd = $POSTJ['mail_sender_acc_pwd'];
	$sender_mailbox = $POSTJ['mail_sender_mailbox'];

	if(empty($sender_pwd))
		$sender_pwd = getSenderPwd($conn, $sender_list_id);

	if(empty($sender_pwd))
		die(json_encode(['result' => 'failed', 'error' => "Sender list does not exist. Please fill the password field"]));	
	else{
		try{
			$imap_obj = imap_open($sender_mailbox,$sender_username,$sender_pwd);		
	    	$resp = ['result' => 'success', 'total_msg_count' => imap_num_msg($imap_obj)];
		} catch (Exception $e) {
	  		$resp = ['result' => 'failed', 'error' =>$e->getMessage()];
		}

		$imap_err = imap_errors(); //required to capture imap errors
		if(!empty($imap_err))
			$resp = ['result' => 'failed', 'error' => $imap_err];	
	}	

	echo json_encode($resp);
}

//---------------------------------------End Sender List Section --------------------------------
//====================================================================================================
function sendTestMailVerification($conn,$POSTJ){
	$sender_list_id = $POSTJ['sender_list_id'];
	$smtp_server = $POSTJ['sender_list_mail_sender_SMTP_server'];
	$sender_from = $POSTJ['sender_list_mail_sender_from'];
	$sender_username = $POSTJ['sender_list_mail_sender_acc_username'];
	$sender_pwd = $POSTJ['sender_list_mail_sender_acc_pwd'];
	$cust_headers = $POSTJ['sender_list_cust_headers'];
	$test_to_address = $POSTJ['test_to_address'];
	$mail_subject = BRAND_PRODUCT_NAME." Test Mail";
	$mail_body = "Success. Here is the test message body";
	$mail_content_type = "text/plain";
	$dsn_type = $POSTJ['dsn_type'];
	$message = (new Email());

	//-----------------------------------
	if(empty($sender_pwd))
		$sender_pwd = getSenderPwd($conn, $sender_list_id);

	if(empty($sender_pwd))
		die(json_encode(['result' => 'failed', 'error' => "Sender list does not exist. Please fill the password field"]));	
	else
		shootMail($message,$smtp_server,$sender_username,$sender_pwd,$sender_from,$test_to_address,$cust_headers,$mail_subject,$mail_body,$mail_content_type,$dsn_type);
}

function sendTestMailSample($conn,$POSTJ){
	$sender_list_id = $POSTJ['sender_list_id'];
	$smtp_server = $POSTJ['smtp_server'];
	$sender_from = $POSTJ['sender_from'];
	$sender_username = $POSTJ['sender_username'];
	$sender_pwd = $POSTJ['sender_pwd'];
	$cust_headers = $POSTJ['cust_headers'];
	$test_to_address = $POSTJ['test_to_address'];
	$mail_subject = $POSTJ['mail_subject'];
	$mail_body = $POSTJ['mail_body'];
	$mail_content_type = $POSTJ['mail_content_type'];
	$mail_attachment = $POSTJ['attachments'];


	$keyword_vals = array();
	$serv_variables = getServerVariable($conn);
	$RID = getRandomStr(10);

    $keyword_vals['{{RID}}'] = $RID;
    $keyword_vals['{{MID}}'] = "MailCampaign_id";
    $keyword_vals['{{NAME}}'] = "ABC XYZ";
    $keyword_vals['{{FNAME}}'] = "ABC";
    $keyword_vals['{{LNAME}}'] = "XYZ";
    $keyword_vals['{{NOTES}}'] = "Note_content";
    $keyword_vals['{{EMAIL}}'] = $test_to_address;
    $keyword_vals['{{FROM}}'] = $sender_from;
    $keyword_vals['{{TRACKINGURL}}'] = $serv_variables['baseurl'].'/tmail?mid='."MailCampaign_id".'&rid='.$RID;
    $keyword_vals['{{TRACKER}}'] = '<img src="'.$keyword_vals['{{TRACKINGURL}}'].'"/>';
    $keyword_vals['{{BASEURL}}'] = $serv_variables['baseurl'];
	$keyword_vals['{{MUSERNAME}}'] = explode('@', $test_to_address)[0];
	$keyword_vals['{{MDOMAIN}}'] = explode('@', $test_to_address)[1];

	if(empty($sender_pwd)){
		$stmt = $conn->prepare("SELECT sender_acc_pwd FROM tb_core_mailcamp_sender_list WHERE sender_list_id = ?");
		$stmt->bind_param("s", $sender_list_id);
		$stmt->execute();
		$result = $stmt->get_result();
		if($row = $result->fetch_assoc())
			$sender_pwd = mail_sender_unseal_pwd($row['sender_acc_pwd']);
		else
			die(json_encode(['result' => 'failed', 'error' => "Sender list does not exist. Please fill the password field"]));
	}

	$message = (new Email());
	$mail_subject = filterKeywords($mail_subject,$keyword_vals);
	$mail_body = filterKeywords($mail_body,$keyword_vals);  	
	$mail_body = filterQRBarCode($mail_body,$keyword_vals,$message);

	foreach ($mail_attachment as $attachment) {
		$file_path = dirname(__FILE__,2).'/uploads/attachments/'.$attachment['file_id'].'.att';
		$file_disp_name = filterKeywords($attachment['file_disp_name'],$keyword_vals);

		if($attachment['inline'])
	    	$message->embedFromPath($file_path, $file_disp_name);
	    else
	    	$message->attachFromPath($file_path, $file_disp_name);
	}

	//---------------------------
	shootMail($message,$smtp_server,$sender_username,$sender_pwd,$sender_from,$test_to_address,$cust_headers,$mail_subject,$mail_body,$mail_content_type);  
}
//===================================================================================================
function getSenderPwd(&$conn, &$sender_list_id){
	$stmt = $conn->prepare("SELECT sender_acc_pwd FROM tb_core_mailcamp_sender_list WHERE sender_list_id = ?");
	$stmt->bind_param("s", $sender_list_id);
	$stmt->execute();
	$result = $stmt->get_result();
	if($row = $result->fetch_assoc())
		return mail_sender_unseal_pwd($row['sender_acc_pwd']); //Phase 3.27: decrypt at-rest envelope
	else
		return "";
}
?>