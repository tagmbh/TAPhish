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
      <title>TAPhish — Sender Toolkit</title>
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
                     <h4 class="page-title">Sender Toolkit</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="row">
                  <div class="col-md-6 mb-4">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Look-alike domain candidates</h5>
                           <p class="text-muted small">Paste the target domain. The generator ranks homoglyph swaps, qwerty-typo neighbours, common insertions, and TLD swaps so you can pick what to register.</p>
                           <div class="input-group mb-3">
                              <input type="text" id="homoglyph_input" class="form-control" placeholder="target.com" autocomplete="off">
                              <div class="input-group-append">
                                 <button class="btn btn-info" type="button" id="btn_homoglyph">Generate</button>
                              </div>
                           </div>
                           <div id="homoglyph_results"></div>
                        </div>
                     </div>
                  </div>
                  <div class="col-md-6 mb-4">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Email-auth posture (SPF / DMARC / MX)</h5>
                           <p class="text-muted small">Inspect the target's SPF, DMARC, and MX records to decide between direct From-spoofing and a look-alike sender.</p>
                           <div class="input-group mb-3">
                              <input type="text" id="dmarc_input" class="form-control" placeholder="target.com" autocomplete="off">
                              <div class="input-group-append">
                                 <button class="btn btn-info" type="button" id="btn_dmarc">Look up</button>
                              </div>
                           </div>
                           <div id="dmarc_results"></div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
            <?php include_once 'z_footer.php' ?>
         </div>
      </div>
      <script src="js/libs/jquery-3.3.1.min.js"></script>
      <?php require_once(dirname(__FILE__) . '/manager/csrf.php'); csrf_emit_script_tag(); ?>
      <script src="js/libs/popper.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="js/libs/waves.js"></script>
      <script src="js/sidebarmenu.js"></script>
      <script src="js/libs/toastr.min.js"></script>
      <script src="js/sender_toolkit.js"></script>
   </body>
</html>
