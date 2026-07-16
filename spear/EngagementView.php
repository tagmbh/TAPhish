<?php
   require_once(dirname(__FILE__) . '/manager/session_manager.php');
   isSessionValid(true);
   $eid = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
   <head>
      <meta charset="utf-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link rel="icon" type="image/png" sizes="16x16" href="images/brand/favicon.png">
      <title>TAPhish — Engagement</title>
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
      <link rel="stylesheet" type="text/css" href="css/toastr.min.css">
   </head>
   <body class="dim-panel">
      <div class="preloader">
         <div class="lds-ripple"><div class="lds-pos"></div><div class="lds-pos"></div></div>
      </div>
      <div id="main-wrapper">
         <?php include_once 'z_menu.php' ?>
         <div class="page-wrapper">
            <div class="page-breadcrumb">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">Engagement</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <input type="hidden" id="eng_view_id" value="<?php echo (int) $eid; ?>">

               <div class="row" id="eng_picker_wrap" style="display:none;">
                  <div class="col-12 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Pick an engagement</h5>
                           <p class="text-muted small">No engagement was specified in the URL. Pick one below to view its campaigns and status.</p>
                           <div class="table-responsive">
                              <table class="table table-sm table-striped" id="eng_picker_table">
                                 <thead><tr><th>Slug</th><th>Window</th><th>Scope</th><th>Status</th><th></th></tr></thead>
                                 <tbody></tbody>
                              </table>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>

               <div class="row" id="eng_header_wrap" style="display:none;">
                  <div class="col-lg-8 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title d-flex align-items-center">
                              <span id="eng_name">—</span>
                              <span class="badge ml-3" id="eng_status_badge">—</span>
                           </h5>
                           <div class="small text-muted" id="eng_meta">—</div>
                           <div class="small mt-2" id="eng_scope">—</div>
                           <div class="mt-3" id="eng_continue_setup"></div>
                        </div>
                     </div>
                  </div>
                  <div class="col-lg-4 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h6 class="card-title">Status</h6>
                           <p class="small text-muted">Transition the engagement through its lifecycle. Conflicting transitions (e.g. double-click) are rejected by the database CAS.</p>
                           <div class="btn-group btn-group-sm" role="group" id="eng_transition_btns">
                              <button type="button" class="btn btn-info"    data-to="live">      Mark live</button>
                              <button type="button" class="btn btn-success" data-to="completed"> Mark completed</button>
                              <button type="button" class="btn btn-danger"  data-to="cancelled"> Cancel</button>
                           </div>
                           <hr>
                           <p class="small text-muted mb-1">Membership is this engagement's visibility boundary &mdash; owners manage who can see its recipients and run its campaigns.</p>
                           <a href="EngagementMembers?engagement_id=<?php echo (int) $eid; ?>" class="btn btn-sm btn-outline-info mb-2">
                              <i class="fa fas fa-users"></i> Manage members
                           </a>
                           <hr>
                           <p class="small text-muted mb-1">Deleting removes the engagement. Linked campaigns are kept but unlinked (their data is never destroyed).</p>
                           <button type="button" class="btn btn-sm btn-outline-danger" id="btn_delete_engagement">
                              <i class="fa fa-trash"></i> Delete engagement
                           </button>
                        </div>
                     </div>
                  </div>
               </div>

               <div class="row" id="eng_campaigns_wrap" style="display:none;">
                  <div class="col-12 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title d-flex align-items-center">
                              Campaigns linked to this engagement
                              <div class="ml-auto">
                                 <!-- Phase 3.47: PDF export. Streams from
                                      EngagementReportExport so the dispatcher
                                      stays JSON-only. -->
                                 <a id="btn_export_pdf" class="btn btn-sm btn-info mr-1" target="_blank" rel="noopener" href="EngagementReportExport?engagement_id=<?php echo (int) $eid; ?>">
                                    <i class="fa fa-file-pdf"></i> Export PDF
                                 </a>
                                 <button id="btn_refresh_eng_view" class="btn btn-sm btn-secondary" type="button">
                                    <i class="fa fa-sync"></i>
                                 </button>
                              </div>
                           </h5>
                           <div class="table-responsive">
                              <table class="table table-sm table-striped" id="eng_campaigns_table">
                                 <thead><tr><th>Campaign</th><th>Scheduled</th><th>Status</th><th></th></tr></thead>
                                 <tbody></tbody>
                              </table>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
            <?php include_once 'z_footer.php' ?>
         </div>
      </div>
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <?php require_once(dirname(__FILE__) . '/manager/csrf.php'); csrf_emit_script_tag(); ?>
      <script src="js/libs/js.cookie.min.js"></script>
      <script src="js/libs/popper.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="js/libs/custom.min.js"></script>
      <script src="js/libs/toastr.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/engagement_view.js"></script>
      <?php include_once 'z_navboot.php' ?>
   </body>
</html>
