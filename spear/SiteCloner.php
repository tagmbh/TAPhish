<?php
   require_once(dirname(__FILE__) . '/manager/session_manager.php');
   isSessionValid(true);
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
   <head>
      <meta charset="utf-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link rel="icon" type="image/png" sizes="16x16" href="images/favicon.png">
      <title>TAPhish - Site Cloner</title>
      <link rel="stylesheet" type="text/css" href="css/select2.min.css">
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
      <link rel="stylesheet" type="text/css" href="css/dataTables.foundation.min.css">
      <link rel="stylesheet" type="text/css" href="css/toastr.min.css">
   </head>
   <body class="dim-panel">
      <div class="preloader">
         <div class="lds-ripple">
            <div class="lds-pos"></div>
            <div class="lds-pos"></div>
         </div>
      </div>
      <div id="main-wrapper">
         <?php include_once(dirname(__FILE__) . '/z_menu.php'); ?>
         <div class="page-wrapper">
            <div class="page-breadcrumb breadcrumb-withbutton">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">Site Cloner</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="row">
                  <div class="col-md-6">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Clone a target page</h5>
                           <p class="text-muted small">
                              Fetches the target URL over HTTPS, rewrites relative links to absolute,
                              downloads referenced CSS and images, strips Content-Security-Policy
                              meta tags, and saves the result under
                              <code>spear/sniperhost/cloned/&lt;slug&gt;/</code>. For authorized
                              engagements only.
                           </p>
                           <form id="frm_clone">
                              <div class="form-group">
                                 <label>Target URL</label>
                                 <input type="url" class="form-control" id="in_url" placeholder="https://example.com/login" required>
                              </div>
                              <div class="form-group">
                                 <label>Slug (a–z, 0–9, dashes)</label>
                                 <input type="text" class="form-control" id="in_slug" placeholder="acme-bank-login" required>
                              </div>
                              <div class="form-group">
                                 <label>Tracker (optional, injected before <code>&lt;/head&gt;</code>)</label>
                                 <select class="form-control" id="sel_tracker">
                                    <option value="">— No tracker —</option>
                                 </select>
                                 <input type="text" class="form-control mt-2" id="in_tracker" placeholder="Or paste a custom tracker script URL">
                                 <small class="form-text text-muted">Picking a tracker fills the URL automatically. The text field is an escape hatch for external trackers.</small>
                              </div>
                              <div class="form-row">
                                 <div class="form-group col-md-6">
                                    <div class="custom-control custom-checkbox">
                                       <input type="checkbox" class="custom-control-input" id="cb_download_css" checked>
                                       <label class="custom-control-label" for="cb_download_css">Download CSS</label>
                                    </div>
                                    <div class="custom-control custom-checkbox">
                                       <input type="checkbox" class="custom-control-input" id="cb_download_images" checked>
                                       <label class="custom-control-label" for="cb_download_images">Download images</label>
                                    </div>
                                 </div>
                                 <div class="form-group col-md-6">
                                    <div class="custom-control custom-checkbox">
                                       <input type="checkbox" class="custom-control-input" id="cb_allow_private">
                                       <label class="custom-control-label" for="cb_allow_private">Allow private/localhost (lab only)</label>
                                    </div>
                                    <div class="custom-control custom-checkbox">
                                       <input type="checkbox" class="custom-control-input" id="cb_force">
                                       <label class="custom-control-label" for="cb_force">Overwrite if slug exists</label>
                                    </div>
                                 </div>
                              </div>
                              <!-- Phase 3.52 task 5: BeEF hook opt-in. -->
                              <div class="form-row">
                                 <div class="form-group col-12">
                                    <div class="custom-control custom-checkbox">
                                       <input type="checkbox" class="custom-control-input" id="cb_beef_hook">
                                       <label class="custom-control-label" for="cb_beef_hook">
                                          Inject BeEF hook before <code>&lt;/body&gt;</code>
                                          <span class="badge badge-warning ml-1">advanced</span>
                                       </label>
                                       <small class="form-text text-muted">
                                          Requires BeEF integration configured under Settings &rarr; General.
                                          Hook script is signature-detected by SmartScreen / Sophos / Symantec /
                                          EDR — landing pages burn faster with the hook on. Use only when the
                                          engagement scope explicitly permits browser-side hooking.
                                       </small>
                                    </div>
                                 </div>
                              </div>
                              <button type="submit" class="btn btn-info" id="btn_clone">
                                 <i class="fa fas fa-cloud-download-alt"></i> Clone
                              </button>
                           </form>
                           <div id="clone_result" class="mt-3"></div>
                        </div>
                     </div>
                  </div>
                  <div class="col-md-6">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title d-flex">
                              Existing clones
                              <button id="btn_refresh" class="btn btn-sm btn-secondary ml-auto" type="button">
                                 <i class="fa fa-sync"></i>
                              </button>
                           </h5>
                           <table class="table table-sm table-striped" id="tb_clones">
                              <thead>
                                 <tr><th>Slug</th><th>Source</th><th>Assets</th><th>Created</th><th></th></tr>
                              </thead>
                              <tbody></tbody>
                           </table>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/js.cookie.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="js/libs/custom.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/site_cloner.js"></script>
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/popper.min.js"></script>
      <script defer src="js/libs/bootstrap.min.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>
