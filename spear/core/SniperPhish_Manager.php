<?php
ini_set('max_execution_time', 0);	//60*60*24*7=604800 =>1 week; 0=infinite
require_once(dirname(__FILE__,2) . '/config/db.php');
require_once(dirname(__FILE__,2) . '/manager/common_functions.php');
require_once(dirname(__FILE__,2) . '/manager/bounce_poll.php');
require_once(dirname(__FILE__) . '/campaign_auto_complete.php');
date_default_timezone_set("UTC");
//---------------------------------------------------------

$os = getOSType();

//Single instance manager (check if 'our' php.exe cron running)
if(isProcessRunning($conn,$os)){
	if($arg_1 != "quite")
		die("Process already running...");	
	return;
}

//Register cron, since cron not running already
$current_pid = getmypid();
$stmt = $conn->prepare("UPDATE tb_main_cron SET pid=?");
$stmt->bind_param('s', $current_pid);
$stmt->execute();

while(true){
	$camp_ids = getScheduledCampaigns($conn);
	foreach ($camp_ids as $campaign_id)
		executeCron($conn,$os,$campaign_id);
	foreach (getTzDeferredCampaigns($conn) as $campaign_id)	//Phase 3.18: resume campaigns whose recipients were outside their local send window
		executeCron($conn,$os,$campaign_id);
	autoCompleteEngagedCampaigns($conn);	//Phase 3.3: terminal-state tracking-phase campaigns whose engagement met threshold
	bounce_poll_cron_pass($conn);	//Phase 3.12: throttled auto-poll of IMAP bounces (60min/campaign)
	sleep(5);
}

//--------------------------------------------------------------------------------------
function getScheduledCampaigns($conn){
	$camp_ids=[];
	$stmt = $conn->prepare("SELECT campaign_id,scheduled_time FROM tb_core_mailcamp_list WHERE camp_status=1 AND camp_lock=0");
	$stmt->execute();
	$result = $stmt->get_result();
	while($row = $result->fetch_assoc()){
		$scheduled_time_plus = strtotime($row['scheduled_time'])-10;
		$current_time =  time();

		if($scheduled_time_plus < $current_time)
			array_push($camp_ids,$row['campaign_id']);
	}
	$stmt->close();
	return $camp_ids;
}

// Phase 3.18: campaigns whose previous send pass deferred some recipients
// for their local-time send window. Re-running executeCron on these makes
// the inner send loop dedup against tb_data_mailcamp_live and pick up
// exactly the deferred entries that are now in-window.
function getTzDeferredCampaigns($conn){
	$camp_ids = [];
	$res = $conn->query("SELECT campaign_id FROM tb_core_mailcamp_list WHERE camp_status=5 AND camp_lock=0");
	if (!$res) return $camp_ids;
	while ($row = $res->fetch_assoc())
		$camp_ids[] = $row['campaign_id'];
	$res->free();
	return $camp_ids;
}
?>