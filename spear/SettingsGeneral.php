<?php
   require_once(dirname(__FILE__) . '/manager/session_manager.php');
   isSessionValid(true);
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
   <head>
      <meta charset="utf-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <!-- Tell the browser to be responsive to screen width -->
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="description" content="">
      <meta name="author" content="">
      <!-- Favicon icon -->
      <link rel="icon" type="image/png" sizes="16x16" href="images/brand/favicon.png">
      <title>TAPhish - Web-Email Spear Phishing Toolkit</title>
      <!-- Custom CSS -->
      <link rel="stylesheet" type="text/css" href="css/select2.min.css">
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
      <link rel="stylesheet" type="text/css" href="css/toastr.min.css">
      <script src="js/libs/clipboard.min.js"></script>  
   </head>
   <body class="dim-panel">
      <!-- ============================================================== -->
      <!-- Preloader - style you can find in spinners.css -->
      <!-- ============================================================== -->
      <div class="preloader">
         <div class="lds-ripple">
            <div class="lds-pos"></div>
            <div class="lds-pos"></div>
         </div>
      </div>
      <!-- ============================================================== -->
      <!-- Main wrapper - style you can find in pages.scss -->
      <!-- ============================================================== -->
      <div id="main-wrapper">
         <!-- ============================================================== -->
         <!-- Topbar header - style you can find in pages.scss -->
         <!-- ============================================================== -->
         <?php include_once 'z_menu.php' ?>
         <!-- ============================================================== -->
         <!-- End Left Sidebar - style you can find in sidebar.scss  -->
         <!-- ============================================================== -->
         <!-- ============================================================== -->
         <!-- Page wrapper  -->
         <!-- ============================================================== -->
         <div class="page-wrapper">
            <!-- ============================================================== -->
            <!-- Bread crumb and right sidebar toggle -->
            <!-- ============================================================== -->
            <div class="page-breadcrumb">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">TAPhish General Settings</h4>
                  </div>
               </div>
            </div>
            <!-- ============================================================== -->
            <!-- End Bread crumb and right sidebar toggle -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- Container fluid  -->
            <!-- ============================================================== -->
            <div class="container-fluid">
               <!-- ============================================================== -->
               <!-- Start Page Content -->
               <!-- ============================================================== -->
               <div class="card">
                  <div class="card-body">
                        <div class="form-group row">
                           <div class="col-md-12">
                              <h6 class="hbar">Timezone & Time Format</h6> 
                           </div>
                        </div>
                        <div class="form-group row">
                           <div class="col-md-12">
                              <i class="small">The date and time of campaign results displayed will change according to timezone and time format set.</i> 
                           </div>
                           <label for="selector_timezone" class="col-md-2 text-left control-label col-form-label">Display Timezone:</label>
                           <div class="col-md-5">
                              <select class="select2 form-control custom-select" id="selector_timezone" style="height: 36px;width: 100%;">
                              </select>
                           </div>
                           <div class="col-md-5 text-right">
                               <button type="button" class="btn btn-info" onclick="modifyTimeStampSettings($(this))"><i class="fa fas fa-save"></i> Save</button>
                           </div>
                        </div>  
                        <div class="form-group row">
                           <label for="report_selector_time_format" class="col-md-2 text-left control-label col-form-label">Display Time Format:</label>
                           <div class="col-md-3">
                              <select class="select2 form-control custom-select" id="selector_date_format" style="height: 36px;width: 100%;">
                              </select>
                              <div class="valid-feedback" id="lb_selector_time_format"></div>
                           </div>
                           <div class="col-md-2">
                              <select class="select2 form-control custom-select" id="selector_space_format" style="height: 36px;width: 100%;">
                                 <option value="space">(Space)</option>
                                 <option value="comma">,(Comma)</option>
                                 <option value="comaspace" selected>, (Comma+Space)</option>
                              </select>
                           </div>
                           <div class="col-md-2">
                              <select class="select2 form-control custom-select" id="selector_time_format" style="height: 36px;width: 100%;">
                              </select>
                           </div>
                        </div>

                        <div class="form-group row">
                           <div class="col-md-12">
                              <h6 class="hbar">TAPhish Primary URL</h6> 
                           </div>
                        </div>
                        <div class="form-group row">
                           <div class="col-md-12">
                              <i class="small">This will act as the primary URL to receive webhooks in all trackers. This should be reachable to target users.</i> 
                           </div> 
                           <label for="selector_timezone" class="col-md-2 text-left control-label col-form-label">TAPhish base URL:</label>
                           <div class="col-md-5 text-left">
                              <input type="text" class="form-control" id="tb_sp_url"> 
                           </div>
                           <div class="col-md-2 text-left">
                              <button type="button" class="btn btn-success btn-sm" data-toggle="tooltip" data-placement="top" onclick="$('#tb_sp_url').val(location.origin);toastr.success('', 'Generated successfully!');" title="Generate URL based on current domain"><i class="fas fa-sync"></i></button>
                           </div>
                           <div class="col-md-3 text-right">
                               <button type="button" class="btn btn-info" onclick="modifySPBaseURL($(this))"><i class="fa fas fa-save"></i> Save</button>
                           </div>
                        </div> 

                        <div class="form-group row">
                           <div class="col-md-12">
                              <h6 class="hbar">Junk Data</h6> 
                           </div>
                        </div>
                        <div class="form-group row">
                           <div class="col-md-12">
                                 <i class="small">This will clear junk files and orphaned records.</i> 
                              </div> 
                           <label for="selector_timezone" class="col-md-2 text-left control-label col-form-label">Clear junk files and data:</label>
                           <div class="col-md-5 text-left">
                               <button type="button" class="btn btn-success" onclick="clearJunkSPData($(this))" title="" data-toggle="tooltip" data-original-title="Clear junk data"><i class="fa fas fa-recycle"></i></button>
                           </div>
                        </div>
                     <hr/>
                     <!-- Phase 3.42: capture webhook URL -->
                     <div class="form-group row">
                        <div class="col-md-12">
                           <h6 class="hbar">First-capture alerting (Slack / Teams / Discord webhook)</h6>
                        </div>
                     </div>
                     <div class="form-group row">
                        <div class="col-md-12">
                           <i class="small">When a recipient submits a phishing form for the first time on a campaign, TAPhish will POST a JSON event to this URL. Stored encrypted at-rest. Leave blank to disable.</i>
                        </div>
                        <label for="capture_webhook_url" class="col-md-2 text-left control-label col-form-label">Webhook URL:</label>
                        <div class="col-md-7">
                           <input type="url" class="form-control" id="capture_webhook_url" placeholder="https://hooks.slack.com/services/T000/B000/XXXX">
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-info" id="btn_save_capture_webhook"><i class="fa fas fa-save"></i> Save</button>
                        </div>
                     </div>
                     <hr/>
                     <!-- Phase 3.53: Telegram bot alerting (parallel channel) -->
                     <div class="form-group row">
                        <div class="col-md-12">
                           <h6 class="hbar">Telegram bot alerting</h6>
                        </div>
                     </div>
                     <div class="form-group row">
                        <div class="col-md-12">
                           <i class="small">
                              A parallel alert channel to the webhook above — fires on the same events (first capture +
                              repeat 2FA). Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>,
                              paste its token, and set the chat id (your numeric user id, a <code>-100…</code> group id,
                              or <code>@channelusername</code>). Stored encrypted at-rest; the token is never shown again
                              after saving. Clear the token and save to disable.
                           </i>
                        </div>
                        <label for="telegram_token" class="col-md-2 text-left control-label col-form-label">Bot token:</label>
                        <div class="col-md-7">
                           <input type="text" class="form-control" id="telegram_token" placeholder="123456789:AAE…" autocomplete="off">
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-info" id="btn_save_telegram"><i class="fa fas fa-save"></i> Save</button>
                        </div>
                     </div>
                     <div class="form-group row">
                        <label for="telegram_chat_id" class="col-md-2 text-left control-label col-form-label">Chat id:</label>
                        <div class="col-md-7">
                           <input type="text" class="form-control" id="telegram_chat_id" placeholder="-1001234567890 or @channel" autocomplete="off">
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-outline-secondary" id="btn_test_telegram"><i class="fa fas fa-paper-plane"></i> Send test</button>
                        </div>
                     </div>
                     <div class="form-group row">
                        <div class="col-md-12"><div id="telegram_test_result" class="small mt-1"></div></div>
                     </div>
                     <hr/>
                     <!-- Phase 3.52: BeEF integration. Operator BYO BeEF;
                          TAPhish stores creds encrypted at-rest and proxies
                          the REST API for the per-clone hook toggle + the
                          hooked-browsers dashboard. Module execution stays
                          in BeEF's own UI. -->
                     <div class="form-group row">
                        <div class="col-md-12">
                           <h6 class="hbar">BeEF integration <span class="badge badge-secondary ml-2">3.52</span></h6>
                        </div>
                     </div>
                     <div class="form-group row">
                        <div class="col-md-12">
                           <div class="alert alert-warning small mb-2">
                              <strong>Anti-malware note.</strong> The BeEF hook script is signature-detected
                              by Microsoft Defender SmartScreen, Sophos, Symantec, and several EDR vendors.
                              Enabling the hook on a landing page reduces its useful lifetime. Use only on
                              engagements where the operator has agreed that hook-detection is acceptable.
                              Per-clone opt-in lives in SiteCloner; this page only stores the credentials.
                           </div>
                           <i class="small">
                              Point this at your BeEF server. Credentials are encrypted at-rest. The
                              password is never returned to this page after save — leave it as the masked
                              placeholder to keep the existing one when editing only URL / username. Leave
                              the URL blank and save to forget the stored credentials entirely.
                           </i>
                        </div>
                        <label for="beef_base_url" class="col-md-2 text-left control-label col-form-label">BeEF base URL:</label>
                        <div class="col-md-7">
                           <input type="url" class="form-control" id="beef_base_url" placeholder="http://beef.ops.example:3000">
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-info" id="btn_save_beef_settings"><i class="fa fas fa-save"></i> Save</button>
                        </div>
                     </div>
                     <div class="form-group row">
                        <label for="beef_username" class="col-md-2 text-left control-label col-form-label">Username:</label>
                        <div class="col-md-3">
                           <input type="text" class="form-control" id="beef_username" placeholder="beef" autocomplete="off">
                        </div>
                        <label for="beef_password" class="col-md-2 text-left control-label col-form-label">Password:</label>
                        <div class="col-md-3">
                           <input type="password" class="form-control" id="beef_password" placeholder="" autocomplete="new-password">
                        </div>
                        <div class="col-md-2 text-right">
                           <button type="button" class="btn btn-outline-secondary" id="btn_test_beef_connection"><i class="fa fas fa-bolt"></i> Test</button>
                        </div>
                     </div>
                     <div class="form-group row">
                        <div class="col-md-12">
                           <div id="beef_test_result" class="small mt-1"></div>
                        </div>
                     </div>
                     <hr/>
                     <!-- Phase 3.57: off-host backup push destination (disaster recovery).
                          Web UI for the backup_push_config.php CLI — the secret is stored
                          encrypted at-rest in tb_store and never returned to the page. -->
                     <div class="form-group row">
                        <div class="col-md-12">
                           <h6 class="hbar">Off-host backup push (disaster recovery) <span class="badge badge-secondary ml-2">3.57</span></h6>
                        </div>
                     </div>
                     <div class="form-group row">
                        <div class="col-md-12">
                           <i class="small">
                              Where <code>backup_run.php --push</code> uploads each encrypted <code>.tapbak</code> for
                              off-site DR. Stored encrypted at-rest; the secret is never shown again after saving —
                              leave it blank to keep the existing one. Choose <strong>None</strong> and save to forget
                              the destination. Equivalent to the <code>backup_push_config.php</code> CLI.
                           </i>
                        </div>
                        <label for="push_type" class="col-md-2 text-left control-label col-form-label">Destination:</label>
                        <div class="col-md-7">
                           <select class="form-control" id="push_type">
                              <option value="">None (disabled)</option>
                              <option value="s3">S3 / S3-compatible</option>
                              <option value="webdav">WebDAV</option>
                           </select>
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-info" id="btn_save_push_settings"><i class="fa fas fa-save"></i> Save</button>
                        </div>
                     </div>
                     <div id="push_s3_fields" style="display:none;">
                        <div class="form-group row">
                           <label for="push_bucket" class="col-md-2 text-left control-label col-form-label">Bucket:</label>
                           <div class="col-md-4">
                              <input type="text" class="form-control" id="push_bucket" placeholder="my-dr-bucket" autocomplete="off">
                           </div>
                           <label for="push_region" class="col-md-2 text-left control-label col-form-label">Region:</label>
                           <div class="col-md-4">
                              <input type="text" class="form-control" id="push_region" placeholder="eu-central-1" autocomplete="off">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="push_access_key" class="col-md-2 text-left control-label col-form-label">Access key:</label>
                           <div class="col-md-4">
                              <input type="text" class="form-control" id="push_access_key" placeholder="AKIA…" autocomplete="off">
                           </div>
                           <label for="push_secret_key" class="col-md-2 text-left control-label col-form-label">Secret key:</label>
                           <div class="col-md-4">
                              <input type="password" class="form-control" id="push_secret_key" placeholder="leave blank to keep existing" autocomplete="new-password">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="push_endpoint" class="col-md-2 text-left control-label col-form-label">Endpoint:</label>
                           <div class="col-md-7">
                              <input type="url" class="form-control" id="push_endpoint" placeholder="(optional) https://minio.example — S3-compatible store">
                           </div>
                           <div class="col-md-3">
                              <div class="custom-control custom-checkbox mt-2">
                                 <input type="checkbox" class="custom-control-input" id="push_path_style">
                                 <label class="custom-control-label" for="push_path_style">Path-style URLs</label>
                              </div>
                           </div>
                        </div>
                     </div>
                     <div id="push_webdav_fields" style="display:none;">
                        <div class="form-group row">
                           <label for="push_url" class="col-md-2 text-left control-label col-form-label">URL:</label>
                           <div class="col-md-7">
                              <input type="url" class="form-control" id="push_url" placeholder="https://dav.example/backups">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="push_user" class="col-md-2 text-left control-label col-form-label">Username:</label>
                           <div class="col-md-4">
                              <input type="text" class="form-control" id="push_user" placeholder="backup" autocomplete="off">
                           </div>
                           <label for="push_pass" class="col-md-2 text-left control-label col-form-label">Password:</label>
                           <div class="col-md-4">
                              <input type="password" class="form-control" id="push_pass" placeholder="leave blank to keep existing" autocomplete="new-password">
                           </div>
                        </div>
                     </div>
                     <div class="form-group row" id="push_actions_row" style="display:none;">
                        <div class="col-md-9">
                           <div id="push_test_result" class="small mt-1"></div>
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-outline-secondary" id="btn_test_push_settings"><i class="fa fas fa-bolt"></i> Test upload</button>
                        </div>
                     </div>
                     <hr/>
                     <!-- OSINT API keys. Stored in the browser's localStorage
                          (never sent to the TAPhish server except inline on the
                          OSINT request itself). One discoverable place to set
                          the keys the QuickStart wizard + Recipient import use. -->
                     <div class="form-group row">
                        <div class="col-md-12">
                           <h6 class="hbar">OSINT API keys</h6>
                        </div>
                     </div>
                     <div class="form-group row">
                        <div class="col-md-12">
                           <i class="small">
                              Stored locally in this browser only (<code>localStorage</code>) — never persisted on the
                              TAPhish server. The QuickStart wizard's OSINT pre-check and the recipient-import
                              email finder read these keys. Clear a field and save to forget it.
                           </i>
                        </div>
                        <label for="hunter_api_key" class="col-md-2 text-left control-label col-form-label">Hunter.io key:</label>
                        <div class="col-md-7">
                           <input type="text" class="form-control" id="hunter_api_key" placeholder="40-char hex key" autocomplete="off">
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-info" id="btn_save_hunter_key"><i class="fa fas fa-save"></i> Save</button>
                        </div>
                     </div>
                     <div class="form-group row">
                        <label for="shodan_api_key" class="col-md-2 text-left control-label col-form-label">Shodan key:</label>
                        <div class="col-md-7">
                           <input type="text" class="form-control" id="shodan_api_key" placeholder="32-char alphanumeric key" autocomplete="off">
                        </div>
                        <div class="col-md-3 text-right">
                           <button type="button" class="btn btn-info" id="btn_save_shodan_key"><i class="fa fas fa-save"></i> Save</button>
                        </div>
                     </div>
                     <hr/>
                  </div>
               </div>
               <!-- ============================================================== -->
               <!-- End PAge Content -->
               <!-- ============================================================== -->
               <!-- ============================================================== -->
               <!-- Right sidebar -->
               <!-- ============================================================== -->
               <!-- .right-sidebar -->
               <!-- ============================================================== -->
               <!-- End Right sidebar -->
               <!-- ============================================================== -->
            </div>
            <!-- ============================================================== -->
            <!-- End Container fluid  -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- footer -->
            <!-- ============================================================== -->
            <?php include_once 'z_footer.php' ?>
            <!-- ============================================================== -->
            <!-- End footer -->
            <!-- ============================================================== -->
         </div>
         <!-- ============================================================== -->
         <!-- End Page wrapper  -->
         <!-- ============================================================== -->
      </div>
      <!-- ============================================================== -->
      <!-- End Wrapper -->
      <!-- ============================================================== -->
      <!-- ============================================================== -->
      <!-- All Jquery -->
      <!-- ============================================================== -->
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/js.cookie.min.js"></script>
      <!-- Bootstrap tether Core JavaScript -->
      <script src="js/libs/popper.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <!--Custom JavaScript -->
      <script src="js/libs/custom.min.js"></script>
      <script src="js/libs/select2.min.js"></script>
      <script src="js/libs/moment.min.js"></script>
      <script src="js/libs/moment-timezone-with-data.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/settings_general.js"></script>      
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>