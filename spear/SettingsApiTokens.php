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
      <meta name="description" content="">
      <meta name="author" content="">
      <!-- Favicon icon -->
      <link rel="icon" type="image/png" sizes="16x16" href="images/brand/favicon.png">
      <title>TAPhish - Web-Email Spear Phishing Toolkit</title>
      <!-- Custom CSS -->
      <link rel="stylesheet" type="text/css" href="css/select2.min.css">
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
      <link rel="stylesheet" type="text/css" href="css/toastr.min.css">
      <link rel="stylesheet" type="text/css" href="css/dataTables.foundation.min.css">
      <script src="js/libs/clipboard.min.js"></script>
   </head>
   <body class="dim-panel">
      <div class="preloader">
         <div class="lds-ripple">
            <div class="lds-pos"></div>
            <div class="lds-pos"></div>
         </div>
      </div>
      <div id="main-wrapper">
         <?php include_once 'z_menu.php' ?>
         <div class="page-wrapper">
            <div class="page-breadcrumb">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">API Tokens</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="card">
                  <div class="card-body">
                     <h5 class="card-title"><i class="fas fa-key mr-2"></i>Personal API Tokens</h5>
                     <p class="text-muted small">
                        Long-lived bearer tokens let a script call the dispatcher endpoints without a
                        browser session. A token acts <strong>as you</strong> &mdash; it can do exactly what
                        your role allows, nothing more. Send it as an <code>Authorization: Bearer &lt;token&gt;</code>
                        header. The secret is shown <strong>once</strong> at creation and is never recoverable;
                        if you lose it, revoke it and mint a new one.
                     </p>
                     <div class="form-group row align-items-end">
                        <div class="col-sm-6">
                           <label for="tb_token_label">Label</label>
                           <input type="text" class="form-control" id="tb_token_label" placeholder="e.g. CI report puller" maxlength="128">
                        </div>
                        <div class="col-sm-3">
                           <button type="button" class="btn btn-info" onclick="mintTokenAction($(this))"><i class="fas fa-plus"></i> Create token</button>
                        </div>
                     </div>
                  </div>
               </div>

               <div class="card">
                  <div class="card-body">
                     <div class="table-responsive">
                        <table id="table_token_list" class="table table-striped table-bordered">
                           <thead>
                              <tr>
                                 <th>#</th>
                                 <th>Label</th>
                                 <th>Created</th>
                                 <th>Last Used</th>
                                 <th>Status</th>
                                 <th>Actions</th>
                              </tr>
                           </thead>
                           <tbody></tbody>
                        </table>
                     </div>
                  </div>
               </div>
            </div>

            <?php include_once 'z_footer.php' ?>

            <!-- Plaintext shown exactly once after mint -->
            <div class="modal fade" id="ModalTokenReveal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
               <div class="modal-dialog modal-large" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Copy your new token now</h5>
                     </div>
                     <div class="modal-body">
                        <p class="small text-muted">This is the only time the secret is shown. Store it in your password manager or CI secret store &mdash; you cannot retrieve it again.</p>
                        <div class="input-group">
                           <input type="text" class="form-control" id="token_reveal_value" readonly style="font-family:monospace;">
                           <div class="input-group-append">
                              <button type="button" class="btn btn-outline-info" onclick="copyRevealedToken()"><i class="fas fa-copy"></i> Copy</button>
                           </div>
                        </div>
                     </div>
                     <div class="modal-footer">
                        <label class="custom-control custom-checkbox mr-auto">
                           <input type="checkbox" class="custom-control-input" id="token_reveal_ack" onchange="$('#token_reveal_close').prop('disabled', !this.checked)">
                           <span class="custom-control-label">I have saved this token somewhere safe</span>
                        </label>
                        <button type="button" class="btn btn-success" id="token_reveal_close" data-dismiss="modal" disabled>Done</button>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Revoke confirmation -->
            <div class="modal fade" id="ModalTokenRevoke" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Revoke this token?</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        Any script using this token will immediately stop working. This can't be undone.
                     </div>
                     <div class="modal-footer">
                        <button type="button" class="btn btn-danger" onclick="revokeTokenAction()">Revoke</button>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/js.cookie.min.js"></script>
      <script src="js/libs/popper.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="js/libs/custom.min.js"></script>
      <script src="js/libs/select2.min.js"></script>
      <script src="js/libs/moment.min.js"></script>
      <script src="js/libs/moment-timezone-with-data.min.js"></script>
      <script src="js/libs/jquery/datatables.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/settings_api_tokens.js"></script>
      <script type="text/javascript">
         var curr_version = "<?php getSniperPhishVersion(); ?>";
         $("#lb_version").text("Version: " + curr_version);
      </script>
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>
