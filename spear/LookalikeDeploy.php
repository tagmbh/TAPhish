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
      <title>TAPhish - Web-Email Spear Phishing Toolkit</title>
      <link rel="stylesheet" type="text/css" href="css/select2.min.css">
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
      <link rel="stylesheet" type="text/css" href="css/toastr.min.css">
      <script src="js/libs/clipboard.min.js"></script>
   </head>
   <body class="dim-panel">
      <div class="preloader"><div class="lds-ripple"><div class="lds-pos"></div><div class="lds-pos"></div></div></div>
      <div id="main-wrapper">
         <?php include_once 'z_menu.php' ?>
         <div class="page-wrapper">
            <div class="page-breadcrumb">
               <div class="row"><div class="col-12 d-flex no-block align-items-center">
                  <h4 class="page-title">Deploy landing page</h4>
               </div></div>
            </div>
            <div class="container-fluid">
               <div class="alert alert-warning alert-rounded small">
                  Only deploy to a look-alike domain you are <strong>authorized to use for this engagement</strong> and already own. TAPhish never registers domains or writes DNS — it emits records for you to paste at your registrar.
               </div>

               <div class="card"><div class="card-body">
                  <h5 class="card-title"><i class="fas fa-globe mr-2"></i>Target</h5>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">Engagement</label>
                     <div class="col-sm-9"><select class="form-control" id="ld_engagement"><option value="">— optional (for the audit trail) —</option></select></div>
                  </div>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">Look-alike domain</label>
                     <div class="col-sm-9"><input type="text" class="form-control" id="ld_domain" placeholder="texti1color.ch"></div>
                  </div>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">Web subdomain</label>
                     <div class="col-sm-9"><input type="text" class="form-control" id="ld_subdomain" placeholder="login (optional — leave blank for the apex)"></div>
                  </div>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">Hosting mode</label>
                     <div class="col-sm-9">
                        <div class="custom-control custom-radio">
                           <input type="radio" class="custom-control-input" id="ld_mode_operator" name="ld_mode" value="operator" checked onchange="ldModeChanged()">
                           <label class="custom-control-label" for="ld_mode_operator">Operator-hosted — download a bundle, upload to your own webspace (cleanest lure)</label>
                        </div>
                        <div class="custom-control custom-radio">
                           <input type="radio" class="custom-control-input" id="ld_mode_hosted" name="ld_mode" value="hosted" onchange="ldModeChanged()">
                           <label class="custom-control-label" for="ld_mode_hosted">TAPhish-hosted — serve under <code>/p/&lt;slug&gt;/</code> on this host (fastest)</label>
                        </div>
                     </div>
                  </div>
                  <div class="form-group row" id="ld_arec_row">
                     <label class="col-sm-3 col-form-label">Your webspace IP</label>
                     <div class="col-sm-9"><input type="text" class="form-control" id="ld_a_record" placeholder="203.0.113.10 (A record for operator-hosted mode)"></div>
                  </div>
                  <div class="form-group row" id="ld_cname_row" style="display:none;">
                     <label class="col-sm-3 col-form-label">CNAME target</label>
                     <div class="col-sm-9"><input type="text" class="form-control" id="ld_cname_target" value="ptbe.autodiscover.li"></div>
                  </div>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">Landing page (clone)</label>
                     <div class="col-sm-9">
                        <select class="form-control" id="ld_slug"><option value="">— select a cloned page —</option></select>
                        <small class="form-text text-muted">No clones yet? Create one in <a href="SiteCloner">Site Cloner</a> or <a href="LandingLibrary">Landing Library</a>.</small>
                     </div>
                  </div>
               </div></div>

               <div class="card"><div class="card-body">
                  <h5 class="card-title"><i class="fas fa-shield-alt mr-2"></i>Mail authentication</h5>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">DKIM selector</label>
                     <div class="col-sm-9"><input type="text" class="form-control" id="ld_selector" value="s1"></div>
                  </div>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">DKIM public key</label>
                     <div class="col-sm-9"><textarea class="form-control" id="ld_dkim_pubkey" rows="2" placeholder="base64 public key (optional — leave blank for a &lt;public-key&gt; placeholder; generate one in the QuickStart wizard)"></textarea></div>
                  </div>
                  <div class="form-group row">
                     <label class="col-sm-3 col-form-label">DMARC rua</label>
                     <div class="col-sm-9"><input type="text" class="form-control" id="ld_dmarc_rua" placeholder="soc@yourdomain (optional)"></div>
                  </div>
                  <button type="button" class="btn btn-info" onclick="ldGenerate($(this))"><i class="fas fa-table"></i> Generate DNS records</button>
                  <button type="button" class="btn btn-success ml-2" id="ld_action_btn" onclick="ldPrimaryAction()"><i class="fas fa-download"></i> Download bundle</button>
               </div></div>

               <div class="card" id="ld_result_card" style="display:none;"><div class="card-body">
                  <h5 class="card-title">DNS records — paste these at your registrar</h5>
                  <div id="ld_published" class="alert alert-success" style="display:none;"></div>
                  <div class="table-responsive">
                     <table class="table table-striped table-bordered" id="ld_dns_table">
                        <thead><tr><th>Type</th><th>Host</th><th>Value</th><th></th><th>Note</th></tr></thead>
                        <tbody></tbody>
                     </table>
                  </div>
               </div></div>
            </div>
            <?php include_once 'z_footer.php' ?>
         </div>
      </div>
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/js.cookie.min.js"></script>
      <script src="js/libs/popper.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="js/libs/custom.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/lookalike_deploy.js"></script>
      <script type="text/javascript">
         var curr_version = "<?php getSniperPhishVersion(); ?>";
         $("#lb_version").text("Version: " + curr_version);
      </script>
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>
