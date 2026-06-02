<?php
   require_once(dirname(__FILE__) . '/manager/session_manager.php');
   isSessionValid(true);

   // Default-credentials warning: render the banner while admin still has the
   // bootstrap "sniperphish" password. Works against both legacy SHA-256 hex
   // and post-migration bcrypt; goes away the moment the admin changes their
   // password — no schema migration, no dismiss flag.
   $show_default_creds_warning = false;
   if (isset($conn)) {
      $stmt = $conn->prepare("SELECT password FROM tb_main WHERE username='admin'");
      if ($stmt) {
         $stmt->execute();
         $result = $stmt->get_result();
         if ($result->num_rows > 0) {
            $stored = $result->fetch_assoc()['password'];
            $show_default_creds_warning = verify_user_password('sniperphish', $stored);
         }
         $stmt->close();
      }
   }
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
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
      <link rel="stylesheet" type="text/css" href="css/toastr.min.css">
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
                     <!--<h4 class="page-title">Home</h4> -->
                  </div>
               </div>
            </div>
            <!-- ============================================================== -->
            <!-- End Bread crumb and right sidebar toggle -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- Container fluid  -->
            <!-- ============================================================== -->
            <div class="container-fluid t-dashboard">
               <?php if ($show_default_creds_warning): ?>
               <div class="alert alert-warning alert-dismissible fade show" role="alert">
                  <strong>Security warning:</strong> the <code>admin</code> account is still using the default password (<code>sniperphish</code>). Change it immediately under
                  <a href="/spear/SettingsUser" class="alert-link">Settings &rarr; User Settings</a>.
                  <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
               </div>
               <?php endif; ?>
               <!-- Phase 3.33: operator metric strip. Numbers filled by JS in Task 7. -->
               <div class="t-metric-strip">
                  <div class="t-metric">
                     <div class="t-metric-label">Active campaigns</div>
                     <div class="t-metric-num" id="m_active_campaigns">&mdash;</div>
                  </div>
                  <div class="t-metric">
                     <div class="t-metric-label">Open rate</div>
                     <div class="t-metric-num" id="m_open_rate">&mdash;</div>
                     <div class="t-metric-trend" id="m_open_rate_trend">&nbsp;</div>
                  </div>
                  <div class="t-metric">
                     <div class="t-metric-label">Click rate</div>
                     <div class="t-metric-num" id="m_click_rate">&mdash;</div>
                     <div class="t-metric-trend" id="m_click_rate_trend">&nbsp;</div>
                  </div>
                  <div class="t-metric">
                     <div class="t-metric-label">Cron worker</div>
                     <div class="t-metric-num" id="m_cron_dot">&middot;</div>
                     <div class="t-metric-trend" id="m_cron_status">checking&hellip;</div>
                  </div>
               </div>

               <!-- Activity feed. Rows are appended by JS in Task 7. -->
               <div class="t-activity">
                  <div class="t-activity-head">
                     <div class="t-activity-title">Recent activity</div>
                     <div class="t-activity-meta">UTC &middot; last 24h</div>
                  </div>
                  <div class="t-activity-body" id="t_activity_body">
                     <div class="t-activity-empty">Loading&hellip;</div>
                  </div>
               </div>

               <!-- Phase 3.52 task 6: hooked-browsers widget. Polls every 30s
                    when the tab is visible. Empty / not-configured / unreachable
                    all degrade gracefully — operator never sees a hard error. -->
               <div class="t-activity" id="t_beef" style="margin-top: 18px;">
                  <div class="t-activity-head">
                     <div class="t-activity-title">
                        BeEF hooked browsers
                        <span class="badge badge-secondary ml-2" id="t_beef_count">—</span>
                     </div>
                     <div class="t-activity-meta" id="t_beef_meta">checking&hellip;</div>
                  </div>
                  <div class="t-activity-body" id="t_beef_body">
                     <div class="t-activity-empty">Loading&hellip;</div>
                  </div>
               </div>

               <!-- Phase 3.46-pre: Quick-action launchpad. Operators hit Home
                    most often as the engagement entry point — these tiles
                    deep-link to the wizard / common tasks so a fresh login
                    is one click away from progressing work. -->
               <div class="t-quickact">
                  <div class="t-quickact-head">
                     <div class="t-quickact-title">Jump back in</div>
                     <div class="t-quickact-meta">one-click entry points</div>
                  </div>
                  <div class="t-quickact-grid">
                     <a class="t-quickact-tile" href="/spear/QuickStart">
                        <div class="t-quickact-icon">&#9889;</div>
                        <div class="t-quickact-name">Quick-Start Wizard</div>
                        <div class="t-quickact-sub">7-step guided engagement: scope &rarr; OSINT &rarr; pretext &rarr; DKIM &rarr; recipients &rarr; landing &rarr; launch</div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/EngagementView">
                        <div class="t-quickact-icon">&#9873;</div>
                        <div class="t-quickact-name">Engagements</div>
                        <div class="t-quickact-sub">Open the engagement workspace &middot; review linked campaigns &middot; transition status</div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/SenderToolkit">
                        <div class="t-quickact-icon">&#9881;</div>
                        <div class="t-quickact-name">Sender Toolkit</div>
                        <div class="t-quickact-sub">Homoglyph candidates &middot; SPF/DMARC posture analyzer</div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/ToolsetChecker">
                        <div class="t-quickact-icon">&#10003;</div>
                        <div class="t-quickact-name">Toolset Checker</div>
                        <div class="t-quickact-sub">Pre-engagement readiness: PHP &middot; DNS &middot; webhook &middot; writable dirs</div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/PretextLibrary">
                        <div class="t-quickact-icon">&#9998;</div>
                        <div class="t-quickact-name">Pretext Library</div>
                        <div class="t-quickact-sub">12 curated starters &middot; clone-to-my-templates</div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/SiteCloner">
                        <div class="t-quickact-icon">&#9783;</div>
                        <div class="t-quickact-name">Site Cloner</div>
                        <div class="t-quickact-sub">Clone a login page &middot; auto-wire to <code>track.php</code></div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/LandingLibrary">
                        <div class="t-quickact-icon">&#128218;</div>
                        <div class="t-quickact-name">Landing Library</div>
                        <div class="t-quickact-sub">Curated clone templates &middot; M365 multi-step &middot; VPN portal &middot; SSO redirect</div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/MailCampaignList">
                        <div class="t-quickact-icon">&#9993;</div>
                        <div class="t-quickact-name">Campaigns</div>
                        <div class="t-quickact-sub">All campaigns &middot; dashboards &middot; capture summaries</div>
                     </a>
                     <a class="t-quickact-tile" href="/spear/SettingsGeneral">
                        <div class="t-quickact-icon">&#9881;</div>
                        <div class="t-quickact-name">Settings</div>
                        <div class="t-quickact-sub">Webhook URL &middot; SMTP &middot; brand &middot; security headers</div>
                     </a>
                  </div>
               </div>
            </div>
            <!-- Modal -->
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
      <script src="js/libs/bootstrap.min.js"></script>
      <!--Wave Effects -->
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <!--Custom JavaScript -->
      <script src="js/libs/custom.min.js"></script>
      <!--This page JavaScript -->
      <script src="js/common_scripts.js"></script>
      <script src="js/dashboard.js"></script>
      <script src="js/home_beef.js"></script>
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>