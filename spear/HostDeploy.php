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
      <title>TAPhish — Push Landing to Host</title>
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
                     <h4 class="page-title">Push Landing to Host</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="row">
                  <div class="col-lg-8">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Deploy a capture landing to a look-alike host</h5>
                           <p class="text-muted small">
                              Renders the landing's <code>{{POST_URL}}</code> to this host's
                              <code>track.php</code> and writes <code>index.html</code> +
                              <code>learn.html</code> + <code>assets/</code> directly into the
                              look-alike host's webspace, then verifies HTTPS. Replaces the manual
                              <code>deploy_hostpoint.sh</code>. (For DNS records / operator bundles /
                              vanity <code>/p/&lt;slug&gt;/</code> hosting, use
                              <a href="/spear/LookalikeDeploy">Deploy Landing Page</a> instead.)
                           </p>
                           <div class="form-group">
                              <label>Landing source</label>
                              <select class="form-control custom-select" id="hd_source"></select>
                              <small class="form-text text-muted">From the landing library and your cloned pages.</small>
                           </div>
                           <div class="form-group">
                              <label>Target look-alike host</label>
                              <select class="form-control custom-select" id="hd_host"></select>
                              <small class="form-text text-muted">Pre-provisioned vhosts under <code>~/www/</code> (the app host and config are excluded).</small>
                           </div>
                           <button type="button" class="btn btn-info" id="hd_deploy">
                              <i class="fa fa-rocket"></i> Deploy
                           </button>
                           <button type="button" class="btn btn-link" id="hd_reload">Reload lists</button>
                           <div id="hd_result" class="mt-3"></div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
            <?php include_once 'z_footer.php' ?>
         </div>
      </div>
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/popper.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/custom.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/host_deploy.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
      <?php include_once 'z_navboot.php' ?>
   </body>
</html>
