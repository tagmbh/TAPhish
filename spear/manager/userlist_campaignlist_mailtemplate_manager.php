<?php
require_once(dirname(__FILE__) . '/session_manager.php');
require_once(dirname(__FILE__) . '/common_functions.php');
require_once(dirname(__FILE__) . '/osint_hunter.php');
require_once(dirname(__FILE__) . '/osint_crt_sh.php');
require_once(dirname(__FILE__) . '/osint_shodan.php');
require_once(dirname(__FILE__) . '/secret_at_rest.php');
require_once(dirname(__FILE__) . '/pretext_library.php');
require_once(dirname(__FILE__) . '/homoglyph.php');
require_once(dirname(__FILE__) . '/dmarc_lookup.php');
require_once(dirname(__FILE__) . '/engagement.php');
require_once(dirname(__FILE__) . '/mx_classify.php');
require_once(dirname(__FILE__) . '/web_fingerprint.php');
require_once(dirname(__FILE__) . '/toolset_checks.php');
require_once(dirname(__FILE__) . '/capture_alerting.php');
require_once(dirname(__FILE__) . '/dkim_helper.php');
require_once(dirname(__FILE__) . '/recipient_import.php');
require_once(dirname(__FILE__) . '/preflight_checks.php');
require_once(dirname(__FILE__) . '/beef_integration.php');
require_once(dirname(__FILE__) . '/landing_library.php');
require_once(dirname(__FILE__) . '/lookalike_deploy.php'); // Phase 3.55
require_once(dirname(__FILE__) . '/site_bundle.php');       // Phase 3.55
require_once(dirname(__FILE__) . '/wizard_tracker_builder.php'); // Phase 3.57
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
		// Phase 3.48 (RBAC): default-deny guard for every action in this
		// dispatcher - unknown/unauthorised actions get 403 + {result:'forbidden'} + audit log.
		require_once(dirname(__FILE__) . '/authz.php');
		taphish_require_authorize_or_die($conn, (string)$POSTJ['action_type'], ['engagement_id' => isset($POSTJ['engagement_id']) ? (int)$POSTJ['engagement_id'] : null]);
		// Phase 3.48b: per-engagement PII scoping. Actions on an EXISTING
		// recipient list are gated by that list's engagement (a new group
		// resolves to engagement_id NULL → passes, then the handler validates +
		// stamps the chosen engagement). make_copy guards its source group.
		$ug_scoped_actions = ['add_user_to_table','save_user_group','update_user','delete_user','download_user','upload_user','get_user_group_from_group_Id_table','delete_user_group_from_group_id','make_copy_user_group'];
		if(in_array($POSTJ['action_type'], $ug_scoped_actions, true) && isset($POSTJ['user_group_id']))
			taphish_user_group_guard_or_die($conn, (string)$POSTJ['user_group_id']);
		if($POSTJ['action_type'] == "add_user_to_table")
			addUserToTable($conn, $POSTJ);
		if($POSTJ['action_type'] == "save_user_group")
			saveUserGroup($conn, $POSTJ['user_group_id'], $POSTJ['user_group_name'], isset($POSTJ['engagement_id']) ? (int)$POSTJ['engagement_id'] : 0);
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
		// Phase 3.54: validate + IDNA-encode candidates via Hostpoint's
		// domain-check endpoint; return only the valid (registrable)
		// names with their punycode form. Capped at 25 so a large
		// candidate set doesn't fan out into 100+ external calls.
		if($POSTJ['action_type'] == "homoglyph_check_candidates") {
			require_once(dirname(__FILE__) . '/domain_check.php');
			$domain = (string)($POSTJ['domain'] ?? '');
			$candidates = taphish_homoglyph_candidates($domain, 60);
			$candidates = array_slice($candidates, 0, 25);
			$checks = [];
			foreach ($candidates as $c) {
				$d = (string)($c['domain'] ?? '');
				if ($d === '' || isset($checks[$d])) continue;
				$checks[$d] = domain_check_one($d);
			}
			echo json_encode([
				'result'     => 'success',
				'domain'     => $domain,
				'candidates' => domain_check_filter_valid($candidates, $checks),
				'checked'    => count($checks),
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
					// Phase 3.48: the creator owns the engagement so engagement-scoped
					// checks (view / transition / launch) admit them on it.
					if (function_exists('taphish_engagement_add_member')) {
						taphish_engagement_add_member($conn, (int)$id, $createdBy, 'owner');
					}
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
		// Phase 3.56: persist wizard progress so the QuickStart wizard
		// is resumable. step + a whitelisted state blob (no secrets).
		if($POSTJ['action_type'] == "wizard_save_progress") {
			$id    = (int)($POSTJ['engagement_id'] ?? 0);
			$step  = (int)($POSTJ['step'] ?? 1);
			$state = is_array($POSTJ['state'] ?? null) ? $POSTJ['state'] : [];
			if ($id <= 0) {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id required']);
			} else {
				$ok = taphish_engagement_set_wizard_progress($conn, $id, $step, $state);
				echo json_encode($ok ? ['result' => 'success', 'step' => max(1, min(7, $step))]
					: ['result' => 'failed', 'error' => 'Could not save progress']);
			}
		}
		// Delete an engagement. Linked campaigns survive (FK nulled).
		if($POSTJ['action_type'] == "delete_engagement") {
			$id = (int)($POSTJ['engagement_id'] ?? 0);
			if ($id <= 0) {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id required']);
			} else {
				$eng = taphish_engagement_get_by_id($conn, $id);
				$unlinked = taphish_engagement_delete($conn, $id);
				if ($unlinked === null) {
					echo json_encode(['result' => 'failed', 'error' => 'Engagement not found or could not be deleted']);
				} else {
					if (function_exists('logIt')) {
						logIt('Engagement deleted: ' . (string)($eng['slug'] ?? ('#' . $id))
							. ' (' . $unlinked . ' campaign(s) unlinked)');
					}
					echo json_encode(['result' => 'success', 'unlinked' => $unlinked]);
				}
			}
		}
		// Phase 3.45b: EngagementView data + status transitions.
		if($POSTJ['action_type'] == "get_engagement_view") {
			$id = (int)($POSTJ['engagement_id'] ?? 0);
			if ($id <= 0) {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id required']);
			} else {
				$eng = taphish_engagement_get_by_id($conn, $id);
				if (!$eng) {
					echo json_encode(['result' => 'failed', 'error' => 'Engagement not found']);
				} else {
					echo json_encode([
						'result' => 'success',
						'engagement' => $eng,
						'campaigns' => taphish_engagement_campaigns($conn, $id),
					]);
				}
			}
		}
		// Phase 3.48: per-engagement membership management. The top-of-file
		// guard already resolved engagement_role from engagement_id, so list
		// needs membership and the mutations need owner/super-admin.
		if($POSTJ['action_type'] == "list_engagement_members") {
			$id = (int)($POSTJ['engagement_id'] ?? 0);
			if ($id <= 0) {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id required']);
			} else {
				$eng = taphish_engagement_get_by_id($conn, $id);
				echo json_encode([
					'result'     => 'success',
					'engagement' => $eng ? ['id' => $id, 'name' => ($eng['name'] ?? ''), 'slug' => ($eng['slug'] ?? '')] : ['id' => $id, 'name' => '', 'slug' => ''],
					'members'    => taphish_engagement_members($conn, $id),
				], JSON_INVALID_UTF8_IGNORE);
			}
		}
		if($POSTJ['action_type'] == "add_engagement_member") {
			$id       = (int)($POSTJ['engagement_id'] ?? 0);
			$username = trim((string)($POSTJ['username'] ?? ''));
			$role     = (string)($POSTJ['role'] ?? 'member');
			if ($id <= 0 || $username === '') {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id and username required']);
			} elseif (!in_array($role, ['owner','member','read-only'], true)) {
				echo json_encode(['result' => 'failed', 'error' => 'Invalid role']);
			} elseif (taphish_engagement_role($conn, $id, $username) !== null) {
				echo json_encode(['result' => 'failed', 'error' => 'That user is already a member.']);
			} else {
				$ok = taphish_engagement_add_member($conn, $id, $username, $role);
				if ($ok) { logIt('Engagement member added: ' . $username . ' to #' . $id . ' as ' . $role); }
				echo json_encode($ok ? ['result' => 'success'] : ['result' => 'failed', 'error' => 'No such user, or could not add.']);
			}
		}
		if($POSTJ['action_type'] == "set_engagement_member_role") {
			$id       = (int)($POSTJ['engagement_id'] ?? 0);
			$username = trim((string)($POSTJ['username'] ?? ''));
			$role     = (string)($POSTJ['role'] ?? '');
			if ($id <= 0 || $username === '') {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id and username required']);
			} else {
				$ok = taphish_engagement_set_member_role($conn, $id, $username, $role);
				if ($ok) { logIt('Engagement member role set: ' . $username . ' on #' . $id . ' -> ' . $role); }
				echo json_encode($ok ? ['result' => 'success'] : ['result' => 'failed', 'error' => 'Could not change role (invalid role, not a member, or last owner).']);
			}
		}
		if($POSTJ['action_type'] == "remove_engagement_member") {
			$id       = (int)($POSTJ['engagement_id'] ?? 0);
			$username = trim((string)($POSTJ['username'] ?? ''));
			if ($id <= 0 || $username === '') {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id and username required']);
			} else {
				$ok = taphish_engagement_remove_member($conn, $id, $username);
				if ($ok) { logIt('Engagement member removed: ' . $username . ' from #' . $id); }
				echo json_encode($ok ? ['result' => 'success'] : ['result' => 'failed', 'error' => 'Could not remove (not a member, or last owner).']);
			}
		}
		// Phase 3.45c Step 4 + Step 5 dispatchers.
		if($POSTJ['action_type'] == "wizard_generate_dkim") {
			$selector = (string)($POSTJ['selector'] ?? 's1');
			$rua      = (string)($POSTJ['dmarc_rua'] ?? '');
			if (!taphish_dkim_validate_selector($selector)) {
				echo json_encode(['result' => 'failed', 'error' => 'Invalid DKIM selector']);
			} else {
				$kp = taphish_dkim_generate_keypair();
				if (!$kp['ok']) {
					echo json_encode(['result' => 'failed', 'error' => $kp['error']]);
				} else {
					echo json_encode([
						'result'          => 'success',
						'selector'        => $selector,
						'private_key_pem' => $kp['private_key_pem'],
						'public_key_b64'  => $kp['public_key_b64'],
						'txt_record'      => $kp['txt_record'],
						'spf_record'      => taphish_dkim_suggested_spf_record(),
						'dmarc_record'    => taphish_dkim_suggested_dmarc_record($rua),
					]);
				}
			}
		}
		// Phase 3.45d: pre-flight gates evaluation. Stateless — the JS
		// passes the full context bundle. SMTP + webhook probes happen
		// only if the operator explicitly asked for them in the wizard.
		if($POSTJ['action_type'] == "wizard_preflight") {
			$ctx = is_array($POSTJ['context'] ?? null) ? $POSTJ['context'] : [];
			$emails = is_array($ctx['recipient_emails'] ?? null) ? $ctx['recipient_emails'] : [];
			// Scope must come from the engagement, NOT the client form field —
			// #eng_scope is empty after the Step 1 form resets / on resume, so
			// trusting it makes the scope gate spuriously fail. Fall back to the
			// client value only when no engagement id is supplied.
			$pf_eid = (int)($POSTJ['engagement_id'] ?? 0);
			$allow  = is_array($ctx['scope_allowlist']  ?? null) ? $ctx['scope_allowlist']  : [];
			if ($pf_eid > 0) {
				$pf_eng = taphish_engagement_get_by_id($conn, $pf_eid);
				if ($pf_eng && isset($pf_eng['scope_allowlist']) && is_array($pf_eng['scope_allowlist'])) {
					$allow = $pf_eng['scope_allowlist'];
				}
			}
			$senderProbe = null;
			if (!empty($ctx['sender_list_id']) && !empty($ctx['probe_sender'])) {
				$senderProbe = function() use ($conn, $ctx) {
					$res = ['ok' => false, 'error' => 'sender probe not yet wired'];
					return $res;
				};
			}
			$landingProbe = function(string $url): array {
				return taphish_preflight_http_get($url);
			};
			$report = taphish_preflight_run_all([
				'recipient_emails'    => $emails,
				'scope_allowlist'     => $allow,
				'target_dmarc_policy' => (string)($ctx['target_dmarc_policy'] ?? ''),
				'sender_domain'       => (string)($ctx['sender_domain']       ?? ''),
				'target_domain'       => (string)($ctx['target_domain']       ?? ''),
				'sender_probe'        => $senderProbe,
				'webhook_url'         => (string)($ctx['webhook_url']         ?? ''),
				'landing_url'         => (string)($ctx['landing_url']         ?? ''),
				'landing_probe'       => $landingProbe,
				'rendered_mail_body'  => (string)($ctx['rendered_mail_body']  ?? ''),
			]);
			echo json_encode(['result' => 'success'] + $report);
		}
		// Phase 3.45d / 3.46: list usable landing-page sources for Step 6.
		// Library now reads from spear/sniperhost/library/ via the
		// landing_library helpers — the hardcoded shortcut list is gone.
		if($POSTJ['action_type'] == "wizard_list_landing_options") {
			$clones = [];
			$base = dirname(__FILE__, 2) . '/sniperhost/cloned';
			if (is_dir($base)) {
				foreach (scandir($base) ?: [] as $entry) {
					if ($entry === '.' || $entry === '..' || !is_dir($base . '/' . $entry)) continue;
					$clones[] = $entry;
				}
				sort($clones);
			}
			$library = array_map(function ($e) {
				return [
					'key'         => $e['slug'],
					'label'       => $e['name'],
					'description' => $e['description'],
					'pattern'     => $e['pattern'],
					'has_2fa'     => $e['has_2fa'],
				];
			}, landing_library_list());
			echo json_encode([
				'result'  => 'success',
				'clones'  => $clones,
				'library' => $library,
			]);
		}
		// Phase 3.46: full library list (used by LandingLibrary page).
		if($POSTJ['action_type'] == "library_list") {
			echo json_encode([
				'result'  => 'success',
				'library' => landing_library_list(),
			]);
		}
		// Phase 3.46: clone a library entry to the operator's sniperhost/cloned/.
		// Substitutes {{POST_URL}} + {{TRACKER_URL}} on the way through.
		if($POSTJ['action_type'] == "library_clone_to_my_sites") {
			$source = (string)($POSTJ['source_slug'] ?? '');
			$dest   = (string)($POSTJ['dest_slug']   ?? '');
			$post_url    = (string)($POSTJ['post_url']    ?? '');
			$tracker_url = (string)($POSTJ['tracker_url'] ?? '');
			$force       = !empty($POSTJ['force']);
			if ($post_url === '') {
				// Default to a sensible track.php on this host.
				$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
				$proto  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $scheme;
				$host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
				$post_url = $host !== '' ? ($proto . '://' . $host . '/track.php') : '/track.php';
			}
			$r = landing_library_clone_to_path($source, $dest, $post_url, $tracker_url, $force);
			if ($r['ok']) {
				logIt('Site cloned: ' . $r['slug'] . ' from library ' . $source);
				echo json_encode(['result' => 'success'] + $r);
			} else {
				echo json_encode(['result' => 'failed', 'error' => $r['err']]);
			}
		}
		// Phase 3.55: look-alike domain deployment (DNS helper + hosted publish).
		if($POSTJ['action_type'] == "lookalike_list_clones") {
			echo json_encode(['result' => 'success', 'clones' => lookalike_list_clones()]);
		}
		if($POSTJ['action_type'] == "lookalike_dns_records") {
			$domain = (string)($POSTJ['domain'] ?? '');
			if ($domain === '') {
				echo json_encode(['result' => 'failed', 'error' => 'A look-alike domain is required.']);
			} else {
				$opts = [
					'mode'         => (string)($POSTJ['mode'] ?? 'operator'),
					'subdomain'    => (string)($POSTJ['subdomain'] ?? ''),
					'a_record'     => (string)($POSTJ['a_record'] ?? ''),
					'cname_target' => (string)($POSTJ['cname_target'] ?? ''),
					'selector'     => (string)($POSTJ['selector'] ?? 's1'),
					'dkim_pubkey'  => (string)($POSTJ['dkim_pubkey'] ?? ''),
					'dmarc_rua'    => (string)($POSTJ['dmarc_rua'] ?? ''),
				];
				echo json_encode(['result' => 'success', 'records' => lookalike_build_dns_records($domain, $opts)]);
			}
		}
		if($POSTJ['action_type'] == "lookalike_publish_hosted") {
			$slug   = (string)($POSTJ['slug'] ?? '');
			$domain = (string)($POSTJ['domain'] ?? '');
			if (!lookalike_validate_vanity_slug($slug)) {
				echo json_encode(['result' => 'failed', 'error' => 'Invalid vanity slug.']);
			} elseif (!is_dir(landing_library_clones_root() . '/' . $slug)) {
				echo json_encode(['result' => 'failed', 'error' => 'No cloned page with that slug — clone one first via Site Cloner or Landing Library.']);
			} else {
				$host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
				$url  = lookalike_hosted_url($host, $slug);
				logIt('Look-alike page published (hosted): /p/' . $slug . '/' . ($domain !== '' ? ' for ' . $domain : ''));
				echo json_encode(['result' => 'success', 'url' => $url]);
			}
		}
		// Phase 3.45d: Launch orchestrator. CAS-protected status
		// transition; on insert failure we revert the engagement back
		// to draft so a retry can proceed.
		if($POSTJ['action_type'] == "wizard_launch_campaign") {
			$engagement_id = (int)($POSTJ['engagement_id'] ?? 0);
			$ctx = is_array($POSTJ['context'] ?? null) ? $POSTJ['context'] : [];

			// Phase 3.57: the linked IDs now drive a real, wired campaign.
			$user_group_id    = (string)($POSTJ['user_group_id']    ?? '');
			$mail_template_id = (string)($POSTJ['mail_template_id'] ?? '');
			$sender_list_id   = (string)($POSTJ['sender_list_id']   ?? '');
			$tracker_id       = (string)($POSTJ['tracker_id']       ?? '');
			$landing_url      = (string)($POSTJ['landing_url']      ?? '');

			if ($engagement_id <= 0) {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id is required']);
			} elseif ($user_group_id === '' || $mail_template_id === '' || $sender_list_id === '') {
				echo json_encode(['result' => 'failed', 'error' => 'user_group_id, mail_template_id and sender_list_id are required']);
			} elseif (!checkAnIDExist($conn, $user_group_id, 'user_group_id', 'tb_core_mailcamp_user_group')) {
				echo json_encode(['result' => 'failed', 'error' => 'Recipient group not found']);
			} elseif (!checkAnIDExist($conn, $mail_template_id, 'mail_template_id', 'tb_core_mailcamp_template_list')) {
				echo json_encode(['result' => 'failed', 'error' => 'Mail template not found']);
			} elseif (!checkAnIDExist($conn, $sender_list_id, 'sender_list_id', 'tb_core_mailcamp_sender_list')) {
				echo json_encode(['result' => 'failed', 'error' => 'Sender profile not found']);
			} elseif ($tracker_id !== '' && !checkAnIDExist($conn, $tracker_id, 'tracker_id', 'tb_core_web_tracker_list')) {
				echo json_encode(['result' => 'failed', 'error' => 'Web tracker not found']);
			} else {
				// The recipient group must belong to this engagement.
				$grpEng = 0;
				$gstmt = $conn->prepare("SELECT engagement_id FROM tb_core_mailcamp_user_group WHERE user_group_id = ?");
				if ($gstmt) {
					$gstmt->bind_param('s', $user_group_id);
					$gstmt->execute();
					$grow = $gstmt->get_result()->fetch_assoc();
					$gstmt->close();
					$grpEng = (isset($grow['engagement_id']) && $grow['engagement_id'] !== null) ? (int)$grow['engagement_id'] : 0;
				}
				if ($grpEng !== $engagement_id) {
					echo json_encode(['result' => 'failed', 'error' => 'Recipient group does not belong to this engagement']);
				} else {
					// Resolve display names for the campaign_data labels.
					$group_name = '';
					$template_name = '';
					$sender_name = '';
					if ($ns = $conn->prepare("SELECT user_group_name FROM tb_core_mailcamp_user_group WHERE user_group_id = ?")) {
						$ns->bind_param('s', $user_group_id); $ns->execute();
						$group_name = (string)($ns->get_result()->fetch_assoc()['user_group_name'] ?? ''); $ns->close();
					}
					if ($nt = $conn->prepare("SELECT mail_template_name FROM tb_core_mailcamp_template_list WHERE mail_template_id = ?")) {
						$nt->bind_param('s', $mail_template_id); $nt->execute();
						$template_name = (string)($nt->get_result()->fetch_assoc()['mail_template_name'] ?? ''); $nt->close();
					}
					if ($nse = $conn->prepare("SELECT sender_name FROM tb_core_mailcamp_sender_list WHERE sender_list_id = ?")) {
						$nse->bind_param('s', $sender_list_id); $nse->execute();
						$sender_name = (string)($nse->get_result()->fetch_assoc()['sender_name'] ?? ''); $nse->close();
					}

					// 1. Re-run preflight so the operator can't bypass the JS.
					// Use TRUSTED server-side sources, NOT the client bundle: the
					// scope allowlist comes from the engagement and the recipient
					// emails are read back from the committed group, so the scope +
					// recipients gates can't be bypassed by spoofing the JS context.
					$engForScope = taphish_engagement_get_by_id($conn, $engagement_id);
					$allow = ($engForScope && isset($engForScope['scope_allowlist']) && is_array($engForScope['scope_allowlist']))
						? $engForScope['scope_allowlist'] : [];
					$emails = [];
					if ($gds = $conn->prepare("SELECT user_data FROM tb_core_mailcamp_user_group WHERE user_group_id = ?")) {
						$gds->bind_param('s', $user_group_id);
						$gds->execute();
						$gdrow = $gds->get_result()->fetch_assoc();
						$gds->close();
						if ($gdrow && isset($gdrow['user_data'])) {
							$plainUsers = json_decode((string) recipient_data_unseal($gdrow['user_data']), true) ?: [];
							foreach ($plainUsers as $pu) {
								$em = strtolower(trim((string)($pu['email'] ?? '')));
								if ($em !== '') $emails[] = $em;
							}
						}
					}
					$landingProbe = function(string $url): array {
						return taphish_preflight_http_get($url);
					};
					$pre = taphish_preflight_run_all([
						'recipient_emails'    => $emails,
						'scope_allowlist'     => $allow,
						'target_dmarc_policy' => (string)($ctx['target_dmarc_policy'] ?? ''),
						'sender_domain'       => (string)($ctx['sender_domain']       ?? ''),
						'target_domain'       => (string)($ctx['target_domain']       ?? ''),
						'sender_probe'        => null,
						'webhook_url'         => (string)($ctx['webhook_url']         ?? ''),
						'landing_url'         => $landing_url !== '' ? $landing_url : (string)($ctx['landing_url'] ?? ''),
						'landing_probe'       => $landingProbe,
						'rendered_mail_body'  => (string)($ctx['rendered_mail_body']  ?? ''),
					]);
					if (!$pre['ok']) {
						echo json_encode(['result' => 'failed', 'error' => 'Pre-flight gates not green', 'gates' => $pre['gates']]);
					}
					// 2. CAS: draft → live.
					elseif (!taphish_engagement_transition_status($conn, $engagement_id, 'draft', 'live')) {
						echo json_encode(['result' => 'failed', 'error' => 'Engagement already launched or cancelled (CAS rejected)']);
					} else {
						// 3. Build the fully-linked campaign_data + INSERT campaign row.
						$campaign_data_arr = taphish_wizard_build_campaign_data([
							'user_group_id'     => $user_group_id,
							'user_group_name'   => $group_name,
							'mail_template_id'  => $mail_template_id,
							'mail_template_name'=> $template_name,
							'sender_list_id'    => $sender_list_id,
							'sender_name'       => $sender_name,
						]);
						$campaign_id    = 'camp-' . substr(bin2hex(random_bytes(6)), 0, 12);
						$campaign_name  = (string)($POSTJ['campaign_name'] ?? ('Quick-Start campaign — engagement ' . $engagement_id));
						$campaign_data  = json_encode($campaign_data_arr);
						$scheduled_time = (string)($POSTJ['scheduled_time'] ?? '');
						$camp_status    = (int)($POSTJ['camp_status']      ?? 0);

						$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_list(campaign_id,campaign_name,campaign_data,date,scheduled_time,camp_status,camp_lock,engagement_id) VALUES(?,?,?,?,?,?,0,?)");
						if ($stmt) {
							$entry_time = $GLOBALS['entry_time'] ?? (new DateTime())->format('d-m-Y h:i A');
							$stmt->bind_param('ssssssi', $campaign_id, $campaign_name, $campaign_data, $entry_time, $scheduled_time, $camp_status, $engagement_id);
							$ok = $stmt->execute();
							$stmt->close();
						} else {
							$ok = false;
						}
						if (!$ok) {
							// Rollback status.
							taphish_engagement_transition_status($conn, $engagement_id, 'live', 'draft');
							echo json_encode(['result' => 'failed', 'error' => 'Campaign insert failed; engagement reverted to draft']);
						} else {
							logIt('Engagement launched: id=' . $engagement_id . ' campaign=' . $campaign_id);
							echo json_encode([
								'result'        => 'success',
								'engagement_id' => $engagement_id,
								'campaign_id'   => $campaign_id,
							]);
						}
					}
				}
			}
		}
		if($POSTJ['action_type'] == "wizard_recipient_preview") {
			$csv = (string)($POSTJ['user_data'] ?? '');
			$allowlist = [];
			if (!empty($POSTJ['engagement_id'])) {
				$eng = taphish_engagement_get_by_id($conn, (int) $POSTJ['engagement_id']);
				if ($eng) {
					$allowlist = $eng['scope_allowlist'] ?? [];
				}
			}
			echo json_encode(['result' => 'success'] + taphish_recipient_preview($csv, $allowlist));
		}
		// Phase 3.57 (full-funnel wizard): list existing web-trackers for the
		// Step 4 dropdown (pick an existing tracker instead of auto-creating).
		if($POSTJ['action_type'] == "wizard_list_web_trackers") {
			$trackers = [];
			$res = mysqli_query($conn, "SELECT tracker_id,tracker_name,active FROM tb_core_web_tracker_list ORDER BY date DESC");
			if ($res) {
				foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $row) {
					$trackers[] = [
						'tracker_id'   => $row['tracker_id'],
						'tracker_name' => $row['tracker_name'],
						'active'       => (int) $row['active'],
					];
				}
			}
			echo json_encode(['result' => 'success', 'trackers' => $trackers], JSON_INVALID_UTF8_IGNORE);
		}
		// Phase 3.57: auto-create a minimal, functional web-tracker server-side
		// (Name + optional webhook URL). Builds tracker_step_data + content_js +
		// content_html via the pure builder, INSERTs active=1, returns the
		// tracker_id + the mod URL the landing page embeds.
		if($POSTJ['action_type'] == "wizard_create_web_tracker") {
			$tracker_name = trim((string)($POSTJ['tracker_name'] ?? ''));
			if ($tracker_name === '') {
				echo json_encode(['result' => 'failed', 'error' => 'tracker_name is required']);
			} else {
				// Resolve the host base the same way the other dispatcher
				// branches do, defaulting the webhook to this host's track.php.
				$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
				$proto  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $scheme;
				$host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
				$base   = $host !== '' ? ($proto . '://' . $host) : '';
				$webhook_url = trim((string)($POSTJ['webhook_url'] ?? ''));
				if ($webhook_url === '') {
					$webhook_url = $base !== '' ? ($base . '/track.php') : '/track.php';
				}
				// Generate a 6-char alnum tracker id (same scheme as the JS generator).
				$tracker_id = getRandomStr(6);
				while (checkAnIDExist($conn, $tracker_id, 'tracker_id', 'tb_core_web_tracker_list')) {
					$tracker_id = getRandomStr(6);
				}
				$built = taphish_wizard_build_minimal_tracker($tracker_id, $tracker_name, $webhook_url);
				$active = 1;
				$stmt = $conn->prepare("INSERT INTO tb_core_web_tracker_list(tracker_id,tracker_name,content_html,content_js,tracker_step_data,active,date) VALUES(?,?,?,?,?,?,?)");
				$ok = false;
				if ($stmt) {
					$stmt->bind_param('sssssis', $tracker_id, $tracker_name, $built['content_html'], $built['content_js'], $built['tracker_step_data'], $active, $GLOBALS['entry_time']);
					$ok = $stmt->execute();
					$stmt->close();
				}
				if ($ok) {
					logIt('Wizard created web-tracker: ' . $tracker_name . ' (' . $tracker_id . ')');
					$mod_url = $base !== '' ? ($base . '/mod?tlink=' . $tracker_id) : ('/mod?tlink=' . $tracker_id);
					echo json_encode(['result' => 'success', 'tracker_id' => $tracker_id, 'mod_url' => $mod_url]);
				} else {
					echo json_encode(['result' => 'failed', 'error' => 'Could not create tracker']);
				}
			}
		}
		// Phase 3.57: persist a recipient group + its (scope-filtered) recipients
		// in one shot. Reuses the pure CSV parse + scope-violation helpers, then
		// replicates the user-group INSERT (we don't call saveUserGroup, which
		// emits its own JSON) so we control a single clean response.
		if($POSTJ['action_type'] == "wizard_commit_recipients") {
			$engagement_id = (int)($POSTJ['engagement_id'] ?? 0);
			// user_group_name is varchar(50); clamp so a long name doesn't trip a
			// strict-mode "Data too long" error and fail the INSERT silently.
			$group_name    = substr(trim((string)($POSTJ['group_name'] ?? '')), 0, 50);
			$csv           = (string)($POSTJ['user_data'] ?? '');
			if ($engagement_id <= 0 || $group_name === '') {
				echo json_encode(['result' => 'failed', 'error' => 'engagement_id and group_name are required']);
			} elseif (!taphish_user_group_can_stamp($conn, $engagement_id)) {
				echo json_encode(['result' => 'failed', 'error' => 'You are not a member of that engagement.']);
			} else {
				$eng = taphish_engagement_get_by_id($conn, $engagement_id);
				if (!$eng) {
					echo json_encode(['result' => 'failed', 'error' => 'Engagement not found']);
				} else {
					$allowlist = $eng['scope_allowlist'] ?? [];
					$parsed = taphish_recipient_csv_parse($csv);
					$skipped = count($parsed['errors']);
					$rows = $parsed['rows'];
					$violations = taphish_recipient_allowlist_violations($rows, $allowlist);
					$scope_violations = count($violations);
					$violationIdx = array_flip(array_column($violations, 'line_index'));
					$arr_users = [];
					foreach ($rows as $i => $r) {
						if (isset($violationIdx[$i])) {
							continue; // out of scope — dropped
						}
						$arr_users[] = [
							'uid'   => getRandomStr(10),
							'fname' => $r['fname'],
							'lname' => $r['lname'] !== '' ? $r['lname'] : null,
							'email' => $r['email'],
							'notes' => '',
						];
					}
					// Generate a unique group id (same random scheme as the UI).
					$user_group_id = getRandomStr(10);
					while (checkAnIDExist($conn, $user_group_id, 'user_group_id', 'tb_core_mailcamp_user_group')) {
						$user_group_id = getRandomStr(10);
					}
					$user_data_sealed = recipient_data_seal(json_encode($arr_users));
					$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,user_data,date,engagement_id) VALUES(?,?,?,?,?)");
					$ok = false;
					if ($stmt) {
						$stmt->bind_param('ssssi', $user_group_id, $group_name, $user_data_sealed, $GLOBALS['entry_time'], $engagement_id);
						$ok = $stmt->execute();
						$stmt->close();
					}
					if ($ok) {
						$committed = count($arr_users);
						logIt('Wizard committed recipients: ' . $group_name . ' (' . $committed . ' committed, ' . $skipped . ' skipped, ' . $scope_violations . ' scope violations)');
						echo json_encode([
							'result'           => 'success',
							'user_group_id'    => $user_group_id,
							'group_name'       => $group_name,
							'committed'        => $committed,
							'skipped'          => $skipped,
							'scope_violations' => $scope_violations,
						]);
					} else {
						echo json_encode(['result' => 'failed', 'error' => 'Could not save recipient group']);
					}
				}
			}
		}
		// Phase 3.45e: per-recipient capture + 2FA summary for the dashboard.
		if($POSTJ['action_type'] == "get_capture_summary_for_campaign") {
			$cid = (string)($POSTJ['campaign_id'] ?? '');
			if ($cid === '') {
				echo json_encode(['result' => 'failed', 'error' => 'campaign_id required']);
			} else {
				echo json_encode([
					'result'   => 'success',
					'captures' => taphish_capture_summary_for_campaign($conn, $cid),
				]);
			}
		}
		if($POSTJ['action_type'] == "engagement_transition_status") {
			$id   = (int)($POSTJ['engagement_id'] ?? 0);
			$from = (string)($POSTJ['from'] ?? '');
			$to   = (string)($POSTJ['to']   ?? '');
			$ok = $id > 0 && taphish_engagement_transition_status($conn, $id, $from, $to);
			if ($ok) {
				logIt('Engagement status: ' . $id . ' ' . $from . ' → ' . $to);
				echo json_encode(['result' => 'success', 'status' => $to]);
			} else {
				echo json_encode(['result' => 'failed', 'error' => 'Transition rejected (concurrent change?)']);
			}
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
		// Phase 3.52 BeEF settings + auth dispatcher actions live in
		// spear/manager/settings_manager.php (alongside the existing
		// settings page actions). This file keeps only the BeEF dashboard
		// actions added in task 6.

		// Phase 3.46-pre: Shodan host lookup. Operator's API key is
		// sent inline so it never persists server-side. Returns open
		// ports + banners + last-update so the wizard can show exposed
		// surface alongside the MX / web-fingerprint lanes.
		if($POSTJ['action_type'] == "osint_shodan_host") {
			$target = (string)($POSTJ['domain'] ?? '');
			$key    = (string)($POSTJ['api_key'] ?? '');
			echo json_encode([
				'result' => 'success',
				'shodan' => osint_shodan_host_lookup($target, $key),
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
	// Phase 3.48b: scope a (possibly new) list to its engagement; COALESCE on
	// update preserves an existing scope. Membership validated when > 0.
	$eid = !empty($POSTJ['engagement_id']) ? (int)$POSTJ['engagement_id'] : 0;
	if(!taphish_user_group_can_stamp($conn, $eid)){
		echo json_encode(['result' => 'failed', 'error' => 'You are not a member of that engagement.']);
		return;
	}
	$eidParam = $eid > 0 ? $eid : null;
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
		$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_group_name=?, user_data=?, engagement_id=COALESCE(engagement_id, ?) WHERE user_group_id=?");
		$stmt->bind_param('ssis', $user_group_name,$user_data_sealed,$eidParam,$user_group_id);
	}
	else{
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,user_data,date,engagement_id) VALUES(?,?,?,?,?)");
		$stmt->bind_param('ssssi', $user_group_id,$user_group_name,$user_data_sealed,$GLOBALS['entry_time'],$eidParam);
	}

	if($stmt->execute() === TRUE){
		echo(json_encode(['result' => 'success']));	
	}
	else 
		echo(json_encode(['result' => 'failed', 'error' => 'Error adding user!']));			
}

function saveUserGroup($conn, $user_group_id, $user_group_name, $engagement_id = 0){
	// Phase 3.35: capture existence before write so create/update verb
	// is accurate in the audit-log entry.
	$is_update = checkAnIDExist($conn,$user_group_id,'user_group_id','tb_core_mailcamp_user_group');
	if($is_update){
		// Rename only — engagement scope is unchanged (the dispatcher guard
		// already enforced the operator's access to this existing list).
		$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_group_name=? WHERE user_group_id=?");
		$stmt->bind_param('ss', $user_group_name,$user_group_id);
	}
	else{
		// Phase 3.48b: a NEW recipient list must be scoped to an engagement the
		// operator belongs to (decision #3 — no unscoped standalone creation).
		$engagement_id = (int)$engagement_id;
		if($engagement_id <= 0){
			echo(json_encode(['result' => 'failed', 'error' => 'Select an engagement for this recipient list.']));
			return;
		}
		if(!taphish_user_group_can_stamp($conn, $engagement_id)){
			echo(json_encode(['result' => 'failed', 'error' => 'You are not a member of that engagement.']));
			return;
		}
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,date,engagement_id) VALUES(?,?,?,?)");
		$stmt->bind_param('sssi', $user_group_id,$user_group_name,$GLOBALS['entry_time'],$engagement_id);
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
	    // Phase 3.46 broader sweep: quoted+escaped filename so a group
	    // name with spaces / semicolons / quotes doesn't produce a
	    // malformed header. header_remove to clear the dispatcher's
	    // earlier application/json header.
	    $safeName = addcslashes((string) $row['user_group_name'], '"\\');
	    header_remove('Content-Type');
	    header('Content-Type: text/csv');
	    header('Content-Disposition: attachment; filename="' . $safeName . '.csv"');
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
	// Phase 3.48b: scope the list to the operator's engagements (super-admin unfiltered).
	$result = mysqli_query($conn, "SELECT user_group_id,user_group_name,user_data,date,engagement_id FROM tb_core_mailcamp_user_group" . taphish_user_group_scope_where($conn));
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

	// Phase 3.48b: scope the list to its engagement. >0 must be one the operator
	// belongs to; 0/absent leaves it unscoped (NULL). COALESCE on update so a
	// re-import never clears an existing scope.
	$eid = !empty($POSTJ['engagement_id']) ? (int)$POSTJ['engagement_id'] : 0;
	if(!taphish_user_group_can_stamp($conn, $eid)){
		echo json_encode(['result' => 'failed', 'error' => 'You are not a member of that engagement.']);
		return;
	}
	$eidParam = $eid > 0 ? $eid : null;

	// Phase 3.45c: parse + scope-check via pure helpers; partial-import
	// rather than die()-ing on the first bad row. If the operator passed
	// an engagement_id, use its scope_allowlist to drop out-of-scope
	// rows instead of silently importing them.
	$allowlist = [];
	if (!empty($POSTJ['engagement_id'])) {
		$eng = taphish_engagement_get_by_id($conn, (int) $POSTJ['engagement_id']);
		if ($eng) {
			$allowlist = $eng['scope_allowlist'] ?? [];
		}
	}
	$preview = taphish_recipient_preview((string) ($POSTJ['user_data'] ?? ''), $allowlist);
	$skipped = [];
	foreach ($preview['parse_errors'] as $e) {
		$skipped[] = ['kind' => 'parse', 'line' => $e['line'], 'email' => $e['email'], 'reason' => $e['reason']];
	}
	$importedRows = $preview['rows'];
	if (!empty($preview['scope_violations'])) {
		$violationIdx = array_flip(array_column($preview['scope_violations'], 'line_index'));
		$kept = [];
		foreach ($importedRows as $i => $r) {
			if (isset($violationIdx[$i])) {
				$skipped[] = ['kind' => 'scope', 'email' => $r['email'], 'reason' => 'out of engagement scope'];
			} else {
				$kept[] = $r;
			}
		}
		$importedRows = $kept;
	}
	$arr_users = [];
	foreach ($importedRows as $r) {
		$arr_users[] = [
			'uid'   => getRandomStr(10),
			'fname' => $r['fname'],
			'lname' => $r['lname'] !== '' ? $r['lname'] : null,
			'email' => $r['email'],
			'notes' => '',
		];
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
		$stmt = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_group_name=?, user_data=?, engagement_id=COALESCE(engagement_id, ?) WHERE user_group_id=?");
		$stmt->bind_param('ssis', $user_group_name,$user_data_sealed,$eidParam,$user_group_id);
	}
	else{
		$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,user_data,date,engagement_id) VALUES(?,?,?,?,?)");
		$stmt->bind_param('ssssi', $user_group_id,$user_group_name,$user_data_sealed,$GLOBALS['entry_time'],$eidParam);
	}

	if($stmt->execute() === TRUE){
		$importedCount = count($arr_users);
		$skippedCount = count($skipped);
		logIt(
			'Recipient list imported: ' . $user_group_name
			. ' (' . $importedCount . ' rows'
			. ($skippedCount > 0 ? ', ' . $skippedCount . ' skipped' : '')
			. ')'
		);
		$payload = ['result' => $skippedCount > 0 ? 'partial' : 'success', 'imported' => $importedCount, 'skipped' => $skipped];
		echo json_encode($payload);
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
	// Phase 3.48b: the copy inherits the source list's engagement scope.
	$stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group (user_group_id,user_group_name,user_data,date,engagement_id) SELECT ?, ?,user_data,?,engagement_id FROM tb_core_mailcamp_user_group WHERE user_group_id=?");
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