<?php
//-------------------Session check-----------------------
require_once(dirname(__FILE__) . '/session_manager.php');
require_once(dirname(__FILE__) . '/log_classifier.php');
if(isSessionValid() == false)
	die("Access denied");
csrf_require();
//-------------------------------------------------------
header('Content-Type: application/json');

if (isset($_POST)) {
	$POSTJ = json_decode(file_get_contents('php://input'),true);

	if(isset($POSTJ['action_type'])){
		if($POSTJ['action_type'] == "get_home_graphs_data")
			getHomeGraphsData($conn);

		if($POSTJ['action_type'] == "check_process")
			checkSniperPhishProcess($conn,false);
		if($POSTJ['action_type'] == "start_process")
			startSniperPhishProcess($conn);
		if($POSTJ['action_type'] == "get_recent_log_entries")
			getRecentLogEntries($conn, (int)($POSTJ['limit'] ?? 10));

		// Phase 3.51: audit log viewer.
		if($POSTJ['action_type'] == "audit_log_query") {
			require_once(dirname(__FILE__) . '/audit_log_query.php');
			$r = audit_log_query($conn, $POSTJ);
			echo json_encode(['result' => 'success'] + $r);
		}

		// Phase 3.52 task 6: hooked-browsers list. Polled every 30 s by
		// the Home page widget. Returns degraded states (not_configured /
		// unreachable / auth_failed) so the UI can give a useful chip
		// rather than a red error.
		if($POSTJ['action_type'] == "beef_list_hooks") {
			require_once(dirname(__FILE__) . '/beef_integration.php');
			$s = beef_settings_load($conn);
			if ($s === null || $s['base_url'] === '') {
				echo json_encode([
					'result' => 'success',
					'state'  => 'not_configured',
					'hooks'  => [],
				]);
			} else {
				$auth = beef_authenticate($s['base_url'], $s['username'], $s['password']);
				if (!$auth['ok']) {
					$state = stripos($auth['err'], 'rejected') !== false
						? 'auth_failed' : 'unreachable';
					if (function_exists('logIt')) {
						logIt('BeEF poll failed: ' . $auth['err']);
					}
					echo json_encode([
						'result' => 'success',
						'state'  => $state,
						'err'    => $auth['err'],
						'hooks'  => [],
					]);
				} else {
					$list = beef_list_hooked_browsers($s['base_url'], $auth['token']);
					if (!$list['ok']) {
						if (function_exists('logIt')) {
							logIt('BeEF poll failed: ' . $list['err']);
						}
						echo json_encode([
							'result' => 'success',
							'state'  => 'unreachable',
							'err'    => $list['err'],
							'hooks'  => [],
						]);
					} else {
						$scope  = beef_collect_active_scope($conn);
						$tagged = beef_tag_hooks_with_scope($list['hooks'], $scope);
						// Audit-log out-of-scope hooks once per poll. Identity is
						// (hook id) which BeEF reuses across polls, so a hook that's
						// seen for 10 polls only logs once per session anyway because
						// the activity feed is rate-limited downstream.
						if (function_exists('logIt')) {
							foreach ($tagged as $t) {
								if (!$t['in_scope']) {
									logIt('BeEF hook out-of-scope: id=' . $t['id']
										. ' on ' . $t['domain']);
								} else {
									logIt('BeEF hook observed: id=' . $t['id']
										. ' on ' . $t['domain']);
								}
							}
						}
						echo json_encode([
							'result' => 'success',
							'state'  => 'ok',
							'hooks'  => $tagged,
							'scope'  => $scope,
						]);
					}
				}
			}
		}
	}
}
//-----------------------------

function getHomeGraphsData($conn){
	$campaign_info= $timestamp_conv= $tmp=[];
	$campaign_info = ['webtracker'=>[], 'mailcamp'=>[], 'quicktracker'=>[]];
	$DTime_info = getTimeInfo($conn);

	$result = mysqli_query($conn, "SELECT tracker_id,tracker_name,date,start_time,stop_time,active FROM tb_core_web_tracker_list");
	if(mysqli_num_rows($result) > 0){
		foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row){
			$tmp['date']=$row['date']; $tmp['start_time']=$row['start_time']; $tmp['stop_time']=$row['stop_time']; 

			$row['date'] = getInClientTime_FD($DTime_info,$row['date']);
			$row['start_time'] = getInClientTime_FD($DTime_info,$row['start_time']);
			$row['stop_time'] = getInClientTime_FD($DTime_info,$row['stop_time']);

			$timestamp_conv[$row['date']] = getTimeInUnix(null,$tmp['date']);
			$timestamp_conv[$row['start_time']] = getTimeInUnix(null,$tmp['start_time']);
			$timestamp_conv[$row['stop_time']] = getTimeInUnix(null,$tmp['stop_time']);

        	array_push($campaign_info['webtracker'],$row);
		}
	}
	else
		$campaign_info['webtracker'] =[];

	$result = mysqli_query($conn, "SELECT campaign_id,campaign_name,date,scheduled_time,stop_time,camp_status FROM tb_core_mailcamp_list");
	if(mysqli_num_rows($result) > 0){
		foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row){
			$tmp['date']=$row['date']; $tmp['scheduled_time']=$row['scheduled_time']; $tmp['stop_time']=$row['stop_time'];

			$row['date'] = getInClientTime_FD($DTime_info,$row['date']);
			$row['scheduled_time'] = getInClientTime_FD($DTime_info,$row['scheduled_time']);
			$row['stop_time'] = getInClientTime_FD($DTime_info,$row['stop_time']);

			$timestamp_conv[$row['date']] = getTimeInUnix(null,$tmp['date']);
			$timestamp_conv[$row['scheduled_time']] = getTimeInUnix(null,$tmp['scheduled_time']);
			$timestamp_conv[$row['stop_time']] = getTimeInUnix(null,$tmp['stop_time']);

        	array_push($campaign_info['mailcamp'],$row);
		}
	}
	else
		$campaign_info['mailcamp'] = [];

	$result = mysqli_query($conn, "SELECT tracker_id,tracker_name,date,start_time,stop_time,active FROM tb_core_quick_tracker_list");
	if(mysqli_num_rows($result) > 0){
		foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row){
			$tmp['date']=$row['date']; $tmp['start_time']=$row['start_time']; $tmp['stop_time']=$row['stop_time']; 

			$row['date'] = getInClientTime_FD($DTime_info,$row['date']);
			$row['start_time'] = getInClientTime_FD($DTime_info,$row['start_time']);
			$row['stop_time'] = getInClientTime_FD($DTime_info,$row['stop_time']);

			$timestamp_conv[$row['date']] = getTimeInUnix(null,$tmp['date']);
			$timestamp_conv[$row['start_time']] = getTimeInUnix(null,$tmp['start_time']);
			$timestamp_conv[$row['stop_time']] = getTimeInUnix(null,$tmp['stop_time']);

        	array_push($campaign_info['quicktracker'],$row);
		}
	}
	else
		$campaign_info['quicktracker'] = [];

	echo json_encode(['campaign_info'=>$campaign_info, 'timestamp_conv'=>$timestamp_conv, 'timezone'=>$DTime_info['time_zone']['timezone']], JSON_INVALID_UTF8_IGNORE);
}

//-------------SniperPhish Process----------
function checkSniperPhishProcess($conn,$quite){
	if(isProcessRunning($conn,getOSType())){
		if($quite == false)
			echo json_encode(['result' => true]);
	    else
	    	return true;
	}
	else{
		if($quite == false)
	    	echo json_encode(['result' => false]);
	    else
	    	return false;
	}
}

function startSniperPhishProcess($conn){
	$os = getOSType();
	if(!isProcessRunning($conn,$os)){	//if process not running
		startProcess($os);
		
		sleep(1);	//wait for process start

		if(isProcessRunning($conn,$os))
			echo json_encode(['result' => true]);
		else			
	    	echo json_encode(['result' => false, 'error'=> 'Error starting service!']);
	}
	else
		echo json_encode(['result' => true]);	//Already running
}

// Phase 3.33: read the last $limit rows from tb_log and classify each
// for the dashboard activity feed. Read-only; the classifier is pure.
function getRecentLogEntries($conn, $limit = 10) {
	$limit = max(1, min(50, (int)$limit));
	$stmt = $conn->prepare(
		"SELECT username, log, date FROM tb_log ORDER BY id DESC LIMIT ?"
	);
	if ($stmt === false) {
		echo json_encode(['result' => 'failed', 'error' => 'Database error']);
		return;
	}
	$stmt->bind_param('i', $limit);
	$stmt->execute();
	$res = $stmt->get_result();
	$rows = [];
	while ($r = $res->fetch_assoc()) {
		$cls = taphish_classify_log_entry((string)($r['log'] ?? ''));
		$rows[] = [
			'time'     => (string)($r['date'] ?? ''),
			'kind'     => $cls['kind'],
			'severity' => $cls['severity'],
			'message'  => (string)($r['log'] ?? ''),
			'username' => (string)($r['username'] ?? ''),
		];
	}
	$stmt->close();
	echo json_encode(['result' => 'success', 'entries' => $rows]);
}
?>