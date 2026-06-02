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
      <title>TAPhish — Pretext Library</title>
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
                     <h4 class="page-title">Pretext Library</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="card">
                  <div class="card-body">
                     <p class="text-muted">Curated red-team pretext starters. Pick one, hit <em>Clone to my templates</em>, then customize the merge fields and replace the placeholder <code>REPLACE-WITH-TRACKER-URL</code> with your tracker / cloned-site URL.</p>
                     <div class="mt-3 mb-3">
                        <input type="text" id="pretext_search" class="form-control" placeholder="Filter by name, subject, tag…">
                     </div>
                     <div id="pretext_gallery" class="t-pretext-gallery">
                        <div class="text-muted">Loading library…</div>
                     </div>
                  </div>
               </div>
            </div>
            <?php include_once 'z_footer.php' ?>
         </div>
      </div>

      <!-- Preview modal -->
      <div class="modal fade" id="modal_pretext_preview" tabindex="-1" role="dialog" aria-hidden="true">
         <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
               <div class="modal-header">
                  <h5 class="modal-title" id="modal_pretext_title">Pretext preview</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
               </div>
               <div class="modal-body">
                  <p class="text-muted small mb-1">Subject</p>
                  <p id="modal_pretext_subject" style="font-family:var(--t-mono);font-size:13px;"></p>
                  <p class="text-muted small mb-1 mt-3">Body</p>
                  <div id="modal_pretext_body" style="background:var(--t-bg);border:1px solid var(--t-border);padding:14px;border-radius:6px;max-height:55vh;overflow:auto;"></div>
               </div>
               <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Close</button>
                  <button type="button" class="btn btn-info" id="modal_pretext_clone_btn"><i class="fas fa-copy"></i> Clone to my templates</button>
               </div>
            </div>
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
      <script src="js/pretext_library.js"></script>
   </body>
</html>
