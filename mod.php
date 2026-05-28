<?php
$_db_file = dirname(__FILE__) . '/spear/config/db.php';
if (!file_exists($_db_file)) {
	http_response_code(404);
	exit;
}
require_once(dirname(__FILE__) . '/spear/libs/qr_barcode/qrcode.php');
require_once(dirname(__FILE__) . '/spear/libs/qr_barcode/barcode.php');
require_once($_db_file);
require_once(dirname(__FILE__) . '/spear/manager/common_functions.php');

$ALLOWED_TYPES = ['qr_ir', 'qr_b64', 'qr_att', 'bar_ir', 'bar_b64', 'bar_att'];

if (isset($_GET['content']) && is_string($_GET['content']))
	$content = $_GET['content'];
else
	$content = ' ';

if (isset($_GET['type']) && is_string($_GET['type']) && in_array($_GET['type'], $ALLOWED_TYPES, true)) {
	switch ($_GET['type']) {
		case 'qr_ir':
		case 'qr_b64':
		case 'qr_att': displayQRImage(); break;
		case 'bar_ir':
		case 'bar_b64':
		case 'bar_att': displayBarcodeImage(); break;
	}
}

if (isset($_GET['tlink']) && is_string($_GET['tlink']))
	getTrackerCode($conn, doFilter($_GET['tlink'], 'ALPHA_NUM'));
if (isset($_GET['mbf']) && is_numeric($_GET['mbf']))
	getMailBodyFile($_GET['mbf']);

//--------------------------------------------------

function displayQRImage(){
	$generator = new barcode_generator();
	if (isset($_GET['options']) && is_array($_GET['options']))
		$options = $_GET['options'];
	else
		$options = ['sx'=>5, 'sf'=>5];

	header('Content-Type: image/png');
	$generator->output_image("png", "qr", $GLOBALS['content'], $options);
}

function displayBarcodeImage(){
	header('Content-Type: image/png');
	echo barcode( "", $GLOBALS['content'], 50, "horizontal", "code128", false, 1);
}

function getTrackerCode($conn, $tracker_id){
	$stmt = $conn->prepare("SELECT content_js FROM tb_core_web_tracker_list WHERE tracker_id = ?");
	$stmt->bind_param("s", $tracker_id);
	$stmt->execute();
	$result = $stmt->get_result();
	header('Content-Type: application/javascript');
	if($result->num_rows != 0){
		$row = $result->fetch_row() ;
		echo ($row[0]) ;
	}
	$stmt->close();
}

function getMailBodyFile($mbf){
	$mbf = doFilter($mbf,'NUM');
	if ($mbf === '')
		return;
	$files = glob('spear/uploads/attachments/*'.$mbf.".mbf");

	if(!empty($files)){
		$file = $files[0];
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime = $finfo ? finfo_file($finfo, $file) : '';
		if ($finfo) finfo_close($finfo);

		// Only serve image/* and video/* — a planted .mbf that's actually a
		// script must NOT trick the browser into executing it.
		if (is_string($mime) && (strpos($mime, 'image/') === 0 || strpos($mime, 'video/') === 0)) {
			header("Content-type: " . $mime);
			readfile($file);
		}
	}
}
?>
