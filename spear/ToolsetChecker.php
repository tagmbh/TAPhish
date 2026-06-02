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
      <title>TAPhish — Toolset Checker</title>
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
                     <h4 class="page-title">Toolset Checker</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="row">
                  <div class="col-12 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Pre-engagement readiness</h5>
                           <p class="text-muted small">
                              Walks the external dependencies every campaign relies on (PHP runtime,
                              extensions, writable upload + cloned-page directories, SPF/DMARC/MX
                              records for your sender domain, first-capture webhook reachability,
                              and /status liveness). Run this before every engagement so the surprise
                              isn't at send time.
                           </p>
                           <div class="form-row align-items-end">
                              <div class="form-group col-md-6 mb-2">
                                 <label>Sender domain (optional)</label>
                                 <input type="text" id="ts_sender" class="form-control" placeholder="phish-sender.example" autocomplete="off">
                                 <small class="form-text text-muted">Checks SPF/DMARC/MX for this domain.</small>
                              </div>
                              <div class="form-group col-md-6 mb-2">
                                 <button class="btn btn-info" type="button" id="ts_run">
                                    <i class="fa fa-stethoscope"></i> Run all checks
                                 </button>
                              </div>
                           </div>
                           <div id="ts_verdict" class="my-3"></div>
                           <div id="ts_results"></div>
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
      <script src="js/toolset_checker.js"></script>
   </body>
</html>
