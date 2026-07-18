<?php
   require_once(dirname(__FILE__) . '/manager/csrf.php');
   require_once(dirname(__FILE__) . '/manager/authz.php'); // Phase 3.48: role-gated nav
   csrf_emit_script_tag();
   echo '<script>window.TAPHISH_MUST_CHANGE_PWD = '
      . (!empty($GLOBALS['TAPHISH_MUST_CHANGE_PWD']) ? 'true' : 'false')
      . ';</script>';
   /*
    * Phase 3.48 (RBAC): hide nav entries the current operator can't use. This
    * is UX only — the dispatcher guard is the real boundary. Falls open (shows
    * the entry) if authz/$conn aren't resolvable, so nav never breaks.
    */
   $nav_conn = $GLOBALS['conn'] ?? null;
   // Resolve the operator's global role once, then decide nav purely (no extra
   // queries per item). The gated entries are all global-role tiers — none need
   // engagement context.
   $nav_role = (function_exists('taphish_current_user_role') && ($nav_conn instanceof \mysqli))
      ? taphish_current_user_role($nav_conn) : null;
   $nav_can  = function (string $action) use ($nav_role): bool {
      if ($nav_role === null || !function_exists('taphish_policy_allows')) {
         return true; // fall open — the dispatcher guard is the real boundary
      }
      return taphish_policy_allows($action, $nav_role);
   };
?>
<header class="topbar" data-navbarbg="skin5">
   <nav class="navbar top-navbar navbar-expand-md navbar-dark">
      <div class="navbar-header" data-logobg="skin5">
         <!-- This is for the sidebar toggle which is visible on mobile only -->
         <a class="nav-toggler waves-effect waves-light d-block d-md-none" href="javascript:void(0)"><i class="fa fas fa-bars"></i></a>
         <!-- ============================================================== -->
         <!-- Logo -->
         <!-- ============================================================== -->
         <a class="navbar-brand" href="/spear/Home">
            <!-- Logo icon -->
            <b class="logo-icon p-l-10">
               <!--You can put here icon as well // <i class="wi wi-sunset"></i> //-->
               <!-- Dark Logo icon -->
               <img src="/spear/images/brand/logo-icon.svg" alt="TAPhish" class="light-logo" />
            </b>
            <!--End Logo icon -->
            <!-- Logo text -->
            <span class="logo-text">
               <!-- dark Logo text -->
               <img src="/spear/images/brand/logo-text-white.svg" alt="TAPhish by T-Alpha GmbH" class="light-logo" />
            </span>
            <!-- Logo icon -->
            <!-- <b class="logo-icon"> -->
            <!--You can put here icon as well // <i class="wi wi-sunset"></i> //-->
            <!-- Dark Logo icon -->
            <!-- <img src="images/brand/logo-text.png" alt="homepage" class="light-logo" /> -->
            <!-- </b> -->
            <!--End Logo icon -->
         </a>
         <!-- ============================================================== -->
         <!-- End Logo -->
         <!-- ============================================================== -->
         <!-- ============================================================== -->
         <!-- Toggle which is visible on mobile only -->
         <!-- ============================================================== -->
         <a class="topbartoggler d-block d-md-none waves-effect waves-light" href="javascript:void(0)" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><i class="fa fas fa-ellipsis-h"></i></a>
      </div>
      <!-- ============================================================== -->
      <!-- End Logo -->
      <!-- ============================================================== -->
      <div class="navbar-collapse collapse" id="navbarSupportedContent" data-navbarbg="skin5">
         <!-- ============================================================== -->
         <!-- toggle and nav items -->
         <!-- ============================================================== -->
         <ul class="navbar-nav float-left mr-auto">
            <li class="nav-item d-none d-md-block"><a class="nav-link sidebartoggler waves-effect waves-light" href="javascript:void(0)" data-sidebartype="mini-sidebar"><i class="mdi mdi-menu font-24"></i></a></li>
            <!-- ============================================================== -->
            <!-- create new (Phase 3.48: operator+ only — every item is a mutation) -->
            <!-- ============================================================== -->
            <?php if ($nav_can('save_engagement')): ?>
            <li class="nav-item dropdown">
               <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
               <span class="d-none d-md-block">Create New <i class="fa fa-angle-down"></i></span>
               <span class="d-block d-md-none"><i class="fa fa-plus"></i></span>
               </a>
               <div class="dropdown-menu" aria-labelledby="navbarDropdown">
                  <a class="dropdown-item" href="/spear/QuickTracker">Quick Tracker</a>
                  <a class="dropdown-item" href="/spear/TrackerGenerator">Web Tracker</a>
                  <a class="dropdown-item" href="/spear/MailCampaignList?action=add&campaign=new">Email Campaign</a>
                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item" href="/spear/MailUserGroup?action=add&user=new">User Group</a>
                  <a class="dropdown-item" href="/spear/MailTemplate?action=add&template=new">Email Template</a>
                  <a class="dropdown-item" href="/spear/MailSender?action=add&sender=new">Email Sender List</a>
               </div>
            </li>
            <?php endif; ?>
         </ul>
         <!-- ============================================================== -->
         <!-- Right side toggle and nav items -->
         <!-- ============================================================== -->
         <ul class="navbar-nav float-right">
            <!-- ============================================================== -->
            <!-- Comment -->
            <!-- ============================================================== -->
            <li class="nav-item dropdown lb-login" hidden>
                <a class="nav-link">Last login: <span></span></a>
            </li>
            <li class="nav-item dropdown" id="top_notifier">
               <a class="nav-link dropdown-toggle waves-effect waves-dark" href="" id="2" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
               <i class="mdi mdi-bell font-24"></i>
               </a>
            </li>
            <!-- ============================================================== -->
            <!-- End Comment -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- User profile and search -->
            <!-- ============================================================== -->
            <li class="nav-item dropdown">
               <a class="nav-link dropdown-toggle text-muted waves-effect waves-dark" href="" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><img src="/spear/images/users/1.png" alt="user" class="rounded-circle pro-pic" width="31"></a>
               <div class="dropdown-menu dropdown-menu-right user-dd animated">
                  <a class="dropdown-item" href="/spear/SettingsUser"><i class="fa far fa-user m-r-5 m-l-5"></i> My Profile <span style="color:#6c757d" class="profile-name"></span></a>
                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item" href="/spear/SettingsGeneral"><i class="fa fas fa-cog m-r-5 m-l-5"></i> General Setting</a>
                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item" href="/spear/logout"><i class="fa fa-power-off m-r-5 m-l-5"></i> Logout</a>
               </div>
            </li>
            <!-- ============================================================== -->
            <!-- User profile and search -->
            <!-- ============================================================== -->
         </ul>
      </div>
   </nav>
</header>
<!-- ============================================================== -->
<!-- End Topbar header -->
<!-- ============================================================== -->
<!-- ============================================================== -->
<!-- Left Sidebar - style you can find in sidebar.scss  -->
<!-- ============================================================== -->
<aside class="left-sidebar" data-sidebarbg="skin5">
   <!-- Sidebar scroll-->
   <div class="scroll-sidebar">
      <!-- Sidebar navigation-->
      <nav class="sidebar-nav">
         <ul id="sidebarnav" class="p-t-30">
            <li class="sidebar-section">Workspace</li>
            <li class="sidebar-item"> <a class="sidebar-link waves-effect waves-dark sidebar-link" href="/spear/Home" aria-expanded="false"><i class="mdi mdi-home"></i><span class="hide-menu">Home</span></a></li>
            <li class="sidebar-item"> <a class="sidebar-link waves-effect waves-dark sidebar-link" href="/spear/QuickStart" aria-expanded="false"><i class="mdi mdi-rocket-launch"></i><span class="hide-menu">Quick Start</span></a></li>
            <li class="sidebar-item"> <a class="sidebar-link waves-effect waves-dark sidebar-link" href="/spear/EngagementView" aria-expanded="false"><i class="mdi mdi-target"></i><span class="hide-menu">Engagements</span></a></li>
            <!-- P2.4: one unified Trackers group (was Quick Tracker + Web Tracker groups + a
                 stray Web Tracker Report leaf). All Trackers = the unified list (web+quick),
                 whose per-row Report/Edit links reach the existing report/builder pages. -->
            <li class="sidebar-item">
               <a class="sidebar-link has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false"><i class="mdi mdi-radar"></i><span class="hide-menu">Trackers </span></a>
               <ul aria-expanded="false" class="collapse  first-level">
                  <li class="sidebar-item"><a href="/spear/Trackers" class="sidebar-link"><i class="fas fa-th-list"></i><span class="hide-menu"> All Trackers </span></a></li>
                  <li class="sidebar-item"><a href="/spear/TrackerReports" class="sidebar-link"><i class="mdi mdi-chart-box-outline"></i><span class="hide-menu"> Reports </span></a></li>
                  <li class="sidebar-item"><a href="/spear/TrackerGenerator" class="sidebar-link"><i class="mdi mdi-web"></i><span class="hide-menu"> New Web Tracker </span></a></li>
                  <li class="sidebar-item"><a href="/spear/QuickTracker" class="sidebar-link"><i class="mdi mdi-watch-vibrate"></i><span class="hide-menu"> New Quick Tracker </span></a></li>
               </ul>
            </li>
            <li class="sidebar-item">
               <a class="sidebar-link has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false"><i class="mdi mdi-email"></i><span class="hide-menu">Email Campaign </span></a>
               <ul aria-expanded="false" class="collapse  first-level">
                  <li class="sidebar-item"><a href="/spear/MailCampaignList" class="sidebar-link"><i class="mdi mdi-playlist-plus"></i><span class="hide-menu"> Campaign List </span></a></li>
                  <li class="sidebar-item"><a href="/spear/MailUserGroup" class="sidebar-link"><i class="fas fa-users"></i><span class="hide-menu"> User Group </span></a></li>
                  <li class="sidebar-item"><a href="/spear/MailTemplate" class="sidebar-link"><i class="mdi mdi-credit-card"></i><span class="hide-menu"> Email Template </span></a></li>
                  <li class="sidebar-item"><a href="/spear/PretextLibrary" class="sidebar-link"><i class="fas fa-book-open"></i><span class="hide-menu"> Pretext Library </span></a></li>
                  <li class="sidebar-item"><a href="/spear/SenderToolkit" class="sidebar-link"><i class="fas fa-tools"></i><span class="hide-menu"> Sender Toolkit </span></a></li>
                  <li class="sidebar-item"><a href="/spear/MailSender" class="sidebar-link"><i class="fas fa-user-secret"></i><span class="hide-menu"> Sender List </span></a></li>
                  <li class="sidebar-item"><a href="/spear/MailConfig" class="sidebar-link"><i class="fas fa-cogs"></i><span class="hide-menu"> Configuration</span></a></li>
               </ul>
            </li>
            <!-- Phase 1: the two dashboard views are folded into ONE "Campaign
                 Dashboard" → WebMailCmpDashboard renders email metrics always and
                 reveals the web-tracker sections via an in-page "Show web tracker"
                 toggle. The old Email-only page (MailCmpDashboard) stays reachable
                 by direct URL for one release but is out of nav. -->
            <li class="sidebar-item"> <a class="sidebar-link waves-effect waves-dark sidebar-link" href="/spear/WebMailCmpDashboard" aria-expanded="false"><i class="mdi mdi-view-dashboard"></i><span class="hide-menu">Campaign Dashboard</span></a></li>
            <!-- Consolidated engagement funnel (delivered→opened→clicked→credentials→OTP,
                 by-wave/cohort, repeat offenders, timeline) over the tested analytics core. -->
            <li class="sidebar-item"> <a class="sidebar-link waves-effect waves-dark sidebar-link" href="/spear/EngagementAnalytics" aria-expanded="false"><i class="mdi mdi-chart-timeline-variant"></i><span class="hide-menu">Engagement Analytics</span></a></li>
            <li class="sidebar-section">Toolkit</li>
            <li class="sidebar-item"> <a class="sidebar-link waves-effect waves-dark sidebar-link" href="/spear/ToolsetChecker" aria-expanded="false"><i class="mdi mdi-stethoscope"></i><span class="hide-menu">Toolset Checker</span></a></li>
            <li class="sidebar-item">
               <a class="sidebar-link has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false"><i class="mdi mdi-cloud"></i><span class="hide-menu">Hosted Pages</span></a>
               <ul aria-expanded="false" class="collapse  first-level">
                  <li class="sidebar-item"><a href="/spear/sniperhost/PlainText" class="sidebar-link"><i class="mdi mdi-format-text"></i><span class="hide-menu"> Plain-Text </span></a></li>
                  <li class="sidebar-item"><a href="/spear/sniperhost/FileHost" class="sidebar-link"><i class="mdi mdi-file-multiple"></i><span class="hide-menu"> Files </span></a></li>
                  <li class="sidebar-item"><a href="/spear/sniperhost/LandingPage" class="sidebar-link"><i class="mdi mdi-google-pages"></i><span class="hide-menu"> Landing Page </span></a></li>
                  <li class="sidebar-item"><a href="/spear/SiteCloner" class="sidebar-link"><i class="mdi mdi-content-copy"></i><span class="hide-menu"> Site Cloner </span></a></li>
                  <li class="sidebar-item"><a href="/spear/LandingLibrary" class="sidebar-link"><i class="mdi mdi-library-books"></i><span class="hide-menu"> Landing Library </span></a></li>
                  <?php if ($nav_can('lookalike_dns_records')): ?>
                  <li class="sidebar-item"><a href="/spear/LookalikeDeploy" class="sidebar-link"><i class="mdi mdi-rocket-launch-outline"></i><span class="hide-menu"> Deploy Landing Page </span></a></li>
                  <?php endif; ?>
                  <?php if ($nav_can('landing_deploy')): ?>
                  <li class="sidebar-item"><a href="/spear/HostDeploy" class="sidebar-link"><i class="mdi mdi-server-network"></i><span class="hide-menu"> Push to Host </span></a></li>
                  <?php endif; ?>
               </ul>
            </li>
            <li class="sidebar-item">
               <a class="sidebar-link has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false"><i class="mdi mdi-settings"></i><span class="hide-menu">Settings</span></a>
               <ul aria-expanded="false" class="collapse  first-level">
                  <li class="sidebar-item"><a href="/spear/SettingsGeneral" class="sidebar-link"><i class="mdi mdi-settings"></i><span class="hide-menu"> General Settings </span></a></li>
                  <li class="sidebar-item"><a href="/spear/SettingsUser" class="sidebar-link"><i class="mdi mdi-account-settings-variant"></i><span class="hide-menu"> User Settings </span></a></li>
                  <?php if ($nav_can('mint_api_token')): ?>
                  <li class="sidebar-item"><a href="/spear/SettingsApiTokens" class="sidebar-link"><i class="mdi mdi-key-variant"></i><span class="hide-menu"> API Tokens </span></a></li>
                  <?php endif; ?>
                  <?php if ($nav_can('audit_log_query')): ?>
                  <li class="sidebar-item"><a href="/spear/SettingsAuditLog" class="sidebar-link"><i class="mdi mdi-clipboard-text-clock"></i><span class="hide-menu"> Audit Log </span></a></li>
                  <?php endif; ?>
                  <?php if ($nav_can('get_logs')): ?>
                  <li class="sidebar-item"><a href="/spear/SPLogs" class="sidebar-link"><i class="mdi mdi-note-text"></i><span class="hide-menu"> Logs </span></a></li>
                  <?php endif; ?>
                  <li class="sidebar-item"><a href="/spear/SPAbout" class="sidebar-link"><i class="mdi mdi-information"></i><span class="hide-menu"> About </span></a></li>
               </ul>
            </li>
         </ul>
      </nav>
      <!-- End Sidebar navigation -->
   </div>
   <!-- End Sidebar scroll-->
   <div class="sidebar-footer">
      <span class="sidebar-footer-version">v<?php echo BRAND_PRODUCT_VERSION; ?></span>
      <span class="sidebar-footer-status" id="sidebar_cron_status">&middot;</span>
   </div>
</aside>