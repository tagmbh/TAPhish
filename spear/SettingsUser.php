<?php
   require_once(dirname(__FILE__) . '/manager/session_manager.php');
   isSessionValid(true);
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
   <head>
      <meta charset="utf-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <!-- Tell the browser to be responsive to screen width -->
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
      <!-- ============================================================== -->
      <!-- Preloader - style you can find in spinners.css -->
      <!-- ============================================================== -->
      <div class="preloader">
         <div class="lds-ripple">
            <div class="lds-pos"></div>
            <div class="lds-pos"></div>
         </div>
      </div>
      <!-- ============================================================== -->
      <!-- Main wrapper - style you can find in pages.scss -->
      <!-- ============================================================== -->
      <div id="main-wrapper">
         <!-- ============================================================== -->
         <!-- Topbar header - style you can find in pages.scss -->
         <!-- ============================================================== -->
         <?php include_once 'z_menu.php' ?>
         <!-- ============================================================== -->
         <!-- End Left Sidebar - style you can find in sidebar.scss  -->
         <!-- ============================================================== -->
         <!-- ============================================================== -->
         <!-- Page wrapper  -->
         <!-- ============================================================== -->
         <div class="page-wrapper">
            <!-- ============================================================== -->
            <!-- Bread crumb and right sidebar toggle -->
            <!-- ============================================================== -->
            <div class="page-breadcrumb">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">TAPhish Settings</h4>
                  </div>
               </div>
            </div>
            <!-- ============================================================== -->
            <!-- End Bread crumb and right sidebar toggle -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- Container fluid  -->
            <!-- ============================================================== -->
            <div class="container-fluid">
               <?php if (!empty($_GET['must_change']) || !empty($GLOBALS['TAPHISH_MUST_CHANGE_PWD'])): ?>
                  <div class="alert alert-danger alert-rounded m-b-20">
                     <strong>Change the default password.</strong>
                     The <code>admin</code> account is still using the bootstrap <code>sniperphish</code> password. You'll keep being redirected here until you change it.
                  </div>
               <?php endif; ?>
               <!-- ============================================================== -->
               <!-- Start Page Content -->
               <!-- ============================================================== -->
               <div class="card">
                  <div class="card-body">
                     <div class="row">
                        <div class="comment-widgets col-md-12">
                             <!-- Comment Row -->
                             <div class="d-flex flex-row comment-row m-t-0">
                                 <div class="p-2"><img src="/spear/images/users/1.png" alt="user" width="150" class="rounded-circle" id="user_dp"></div>
                                 <div class="comment-text w-200">
                                     <h4 class="font-medium m-b-20" id="lb_name"></h4>
                                     <span class="m-b-10 d-block">User Name: <span class="m-l-5" id="lb_uname"></span></span> 
                                     <span class="m-b-10 d-block">Email: <span class="m-l-5" id="lb_mail"></span></span>  
                                     <span class="m-b-15 d-block">Account Created: <span class="m-l-5" id="lb_created_date"></span></span> 
                                     <div class="comment-footer">
                                         <button type="button" class="btn btn-cyan btn-sm" id="bt_edit_current_user">Edit Details</button>
                                     </div>
                                 </div>
                             </div>
                          </div>
                     </div>
                     <hr/>

                     <div class="row">
                        <div class="col-md-12">
                           <h5 class="card-title text-center m-t-10"><span>TAPhish Accounts</span></h5>
                        </div>
                     </div>
                     <div class="row">
                        <div class="col-md-12">  
                           <div class="ml-auto text-right">
                              <button type="button" class="btn btn-info btn-sm" data-toggle="modal" data-target="#ModalAddUser"><i class="fas fa-plus"></i> Create New Admin</button>
                           </div>
                        </div>
                     </div>
                     
                     <div class="row">
                        <div class="col-md-12 m-t-20">
                            <div class="row">
                              <div class="table-responsive">
                                 <table id="table_user_list" class="table table-striped table-bordered">
                                    <thead>
                                       <tr>
                                          <th>#</th>
                                          <th>Name</th>
                                          <th>Username</th>
                                          <th>Email</th>
                                          <th>Role</th>
                                          <th>Date Created</th>
                                          <th>Last Login</th>
                                          <th>Actions</th>
                                       </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                 </table>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
                  <!-- Phase 3.25: Two-factor authentication -->
                  <div class="card">
                     <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                           <h5 class="card-title mb-0"><i class="fas fa-shield-alt mr-2"></i>Two-Factor Authentication (TOTP)</h5>
                           <span class="badge ml-3" id="totp_status_badge">…</span>
                        </div>
                        <p class="text-muted small">Adds a 6-digit code from an authenticator app (Google Authenticator, Authy, 1Password, Bitwarden, etc.) to your login. A leaked password alone is no longer enough.</p>
                        <button type="button" class="btn btn-info" id="btn_totp_enable" onclick="totpStartEnrollment()"><i class="fas fa-qrcode"></i> Enable 2FA</button>
                        <button type="button" class="btn btn-outline-danger" id="btn_totp_disable" data-toggle="modal" data-target="#modal_totp_disable" style="display:none;"><i class="fas fa-shield-virus"></i> Disable 2FA</button>
                        <!-- Phase 3.31: recovery code controls, only shown when 2FA is on -->
                        <button type="button" class="btn btn-outline-secondary" id="btn_totp_regenerate" data-toggle="modal" data-target="#modal_totp_regenerate" style="display:none;"><i class="fas fa-key"></i> Regenerate recovery codes</button>
                        <div class="small text-muted mt-2" id="totp_recovery_summary" style="display:none;"></div>
                     </div>
                  </div>

                  <!-- 2FA recovery codes shown after enrollment / regeneration -->
                  <div class="modal fade" id="modal_totp_recovery_codes" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
                     <div class="modal-dialog" role="document">
                        <div class="modal-content">
                           <div class="modal-header">
                              <h5 class="modal-title">Save your recovery codes</h5>
                           </div>
                           <div class="modal-body">
                              <p class="small text-muted">If you lose access to your authenticator app, each of these one-shot codes can stand in for a TOTP code <strong>exactly once</strong>. Print them, store them in a password manager, or write them on paper. They are not shown again.</p>
                              <pre id="totp_recovery_codes_list" style="background:#f5f7fa;border:1px solid #dde3ea;padding:12px;border-radius:4px;font-size:14px;line-height:1.7;text-align:center;letter-spacing:2px;"></pre>
                              <div class="text-center">
                                 <button type="button" class="btn btn-sm btn-outline-info" onclick="totpCopyRecoveryCodes()"><i class="fas fa-copy"></i> Copy all</button>
                                 <button type="button" class="btn btn-sm btn-outline-info" onclick="totpDownloadRecoveryCodes()"><i class="fas fa-download"></i> Download .txt</button>
                              </div>
                              <div id="totp_recovery_warning" class="text-warning small mt-2" style="display:none;"></div>
                           </div>
                           <div class="modal-footer">
                              <label class="custom-control custom-checkbox mr-auto">
                                 <input type="checkbox" class="custom-control-input" id="totp_recovery_ack" onchange="$('#totp_recovery_close').prop('disabled', !this.checked)">
                                 <span class="custom-control-label">I have saved these codes somewhere safe</span>
                              </label>
                              <button type="button" class="btn btn-success" id="totp_recovery_close" data-dismiss="modal" disabled>Done</button>
                           </div>
                        </div>
                     </div>
                  </div>

                  <!-- Regenerate confirmation modal -->
                  <div class="modal fade" id="modal_totp_regenerate" tabindex="-1" role="dialog" aria-hidden="true">
                     <div class="modal-dialog" role="document">
                        <div class="modal-content">
                           <div class="modal-header">
                              <h5 class="modal-title">Regenerate recovery codes</h5>
                              <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                           </div>
                           <div class="modal-body">
                              <p class="small text-muted">All current recovery codes (used and unused) are wiped and 10 new ones take their place. Enter your current 2FA code to confirm — a stolen session alone can't mint new bypass codes this way.</p>
                              <div class="form-group">
                                 <label>2FA code</label>
                                 <input type="text" class="form-control" id="totp_regenerate_code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7">
                              </div>
                              <div id="totp_regenerate_err" class="text-danger small"></div>
                           </div>
                           <div class="modal-footer">
                              <button type="button" class="btn btn-warning" onclick="totpDoRegenerate($(this))"><i class="fas fa-key"></i> Regenerate</button>
                           </div>
                        </div>
                     </div>
                  </div>

                  <!-- 2FA enrollment modal -->
                  <div class="modal fade" id="modal_totp_enroll" tabindex="-1" role="dialog" aria-hidden="true">
                     <div class="modal-dialog" role="document">
                        <div class="modal-content">
                           <div class="modal-header">
                              <h5 class="modal-title">Enable 2FA — scan with your authenticator app</h5>
                              <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                           </div>
                           <div class="modal-body text-center">
                              <p class="small text-muted">Scan this QR code with Google Authenticator, Authy, 1Password, Bitwarden, or any TOTP app. Then enter the 6-digit code below to confirm.</p>
                              <img id="totp_enroll_qr" alt="2FA QR code" style="max-width:240px;border:1px solid #dde3ea;border-radius:4px;padding:4px;">
                              <div class="mt-2 small text-muted">Or enter the secret manually: <code id="totp_enroll_secret_text"></code></div>
                              <input type="hidden" id="totp_enroll_secret">
                              <div class="mt-3">
                                 <input type="text" class="form-control text-center" id="totp_enroll_code" inputmode="numeric" pattern="[0-9 ]{6,7}" placeholder="6-digit code" autocomplete="one-time-code" maxlength="7">
                              </div>
                              <div id="totp_enroll_err" class="text-danger small mt-2"></div>
                           </div>
                           <div class="modal-footer">
                              <button type="button" class="btn btn-success" onclick="totpConfirmEnrollment($(this))"><i class="fas fa-check"></i> Confirm and enable</button>
                           </div>
                        </div>
                     </div>
                  </div>

                  <!-- 2FA disable modal -->
                  <div class="modal fade" id="modal_totp_disable" tabindex="-1" role="dialog" aria-hidden="true">
                     <div class="modal-dialog" role="document">
                        <div class="modal-content">
                           <div class="modal-header">
                              <h5 class="modal-title">Disable 2FA</h5>
                              <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                           </div>
                           <div class="modal-body">
                              <p class="small text-muted">Both your current password AND a current 2FA code are required. This protects against a stolen session being used to weaken the account.</p>
                              <div class="form-group">
                                 <label>Current password</label>
                                 <input type="password" class="form-control" id="totp_disable_pwd">
                              </div>
                              <div class="form-group">
                                 <label>2FA code</label>
                                 <input type="text" class="form-control" id="totp_disable_code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7">
                              </div>
                              <div id="totp_disable_err" class="text-danger small"></div>
                           </div>
                           <div class="modal-footer">
                              <button type="button" class="btn btn-danger" onclick="totpDoDisable($(this))"><i class="fas fa-shield-virus"></i> Disable 2FA</button>
                           </div>
                        </div>
                     </div>
                  </div>
                  <!-- ============================================================== -->
                  <!-- End PAge Content -->
                  <!-- ============================================================== -->
                  <!-- ============================================================== -->
                  <!-- Right sidebar -->
                  <!-- ============================================================== -->
                  <!-- .right-sidebar -->
                  <!-- ============================================================== -->
                  <!-- End Right sidebar -->
                  <!-- ============================================================== -->
               </div>
               <!-- ============================================================== -->
               <!-- End PAge Content -->
               <!-- ============================================================== -->
               <!-- ============================================================== -->
               <!-- Right sidebar -->
               <!-- ============================================================== -->
               <!-- .right-sidebar -->
               <!-- ============================================================== -->
               <!-- End Right sidebar -->
               <!-- ============================================================== -->
            </div>
            <!-- ============================================================== -->
            <!-- End Container fluid  -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- footer -->
            <!-- ============================================================== -->
            <?php include_once 'z_footer.php' ?>
            <!-- ============================================================== -->
            <!-- End footer -->
            <!-- ============================================================== -->
            <!-- Modal -->
            <div class="modal fade" id="ModalUserDelete" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Are you sure?</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        This will delete user and the action can't be undone!
                     </div>
                     <div class="modal-footer" >
                        <button type="button" class="btn btn-danger" data-tracker_id="" onclick="deleteAccountAction()">Delete</button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Modal -->            
            <div class="modal fade" id="ModalAddUser" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
               <div class="modal-dialog modal-large" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Create New Admin User</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        <div class="form-group row">
                           <label for="rb_dp" class="col-sm-3 text-left control-label col-form-label">Avatar:</label>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/1.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_add_dp]').val([1])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbp1" name="rb_add_dp" value="1" checked>
                                   <label class="custom-control-label" for="rbp1"> </label>
                               </div>
                           </div>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/2.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_add_dp]').val([2])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbp2" name="rb_add_dp" value="2">
                                   <label class="custom-control-label" for="rbp2"> </label>
                               </div>
                           </div>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/3.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_add_dp]').val([3])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbp3" name="rb_add_dp" value="3">
                                   <label class="custom-control-label" for="rbp3"> </label>
                               </div>
                           </div>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/4.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_add_dp]').val([4])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbp4" name="rb_add_dp" value="4">
                                   <label class="custom-control-label" for="rbp4"> </label>
                               </div>
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_add_name" class="col-sm-3 text-left control-label col-form-label">Name:</label>
                           <div class="col-sm-9">
                              <input type="text" class="form-control" id="tb_add_name">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_add_uname" class="col-sm-3 text-left control-label col-form-label">Username:</label>
                           <div class="col-sm-9">
                              <input type="text" class="form-control" id="tb_add_uname">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_add_mail" class="col-sm-3 text-left control-label col-form-label">Email:</label>
                           <div class="col-sm-9">
                              <input type="text" class="form-control" id="tb_add_mail">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_add_role" class="col-sm-3 text-left control-label col-form-label">Role:</label>
                           <div class="col-sm-9">
                              <select class="form-control" id="tb_add_role">
                                 <option value="operator" selected>Operator — run engagements (recon + mutations)</option>
                                 <option value="super-admin">Super Admin — full access incl. users, settings, audit</option>
                                 <option value="read-only">Read-only — view dashboards, no changes</option>
                                 <option value="disabled">Disabled — cannot sign in</option>
                              </select>
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_add_pwd" class="col-sm-3 text-left control-label col-form-label">Password:</label>
                           <div class="col-sm-9">
                              <input type="password" class="form-control" id="tb_add_pwd" placeholder="Password Here">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_add_confirm_pwd" class="col-sm-3 text-left control-label col-form-label">Confirm Password:</label>
                           <div class="col-sm-9">
                              <input type="password" class="form-control" id="tb_add_confirm_pwd" placeholder="Confirm Password Here">
                           </div>
                        </div>
                        <hr/>
                        <div class="form-group row">
                           <label for="tb_update_current_pwd" class="col-sm-3 text-left control-label col-form-label">Your Password:</label>
                           <div class="col-sm-9">
                              <input type="password" class="form-control" id="tb_add_current_pwd" placeholder="Your Password Here">
                           </div>
                        </div>
                     </div>
                     <div class="modal-footer" >
                        <button type="button" class="btn btn-success" onclick="addUserAction($(this))"><i class="fa fas fa-plus"></i> Add</button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Modal -->            
            <div class="modal fade" id="ModalModifyUser" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
               <div class="modal-dialog modal-large" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Update User Info - <span id="modal_title_name"></span></h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        <div class="form-group row">
                           <label for="rb_dp" class="col-sm-3 text-left control-label col-form-label">Avatar:</label>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/1.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_update_dp]').val([1])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbu1" name="rb_update_dp" value="1" checked>
                                   <label class="custom-control-label" for="rbu1"> </label>
                               </div>
                           </div>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/2.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_update_dp]').val([2])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbu2" name="rb_update_dp" value="2">
                                   <label class="custom-control-label" for="rbu2"> </label>
                               </div>
                           </div>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/3.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_update_dp]').val([3])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbu3" name="rb_update_dp" value="3">
                                   <label class="custom-control-label" for="rbu3"> </label>
                               </div>
                           </div>
                           <div class="col-sm-2">
                              <div class="p-2"><img src="/spear/images/users/4.png" alt="user" width="50" class="rounded-circle" onclick="$('input[name=rb_update_dp]').val([4])"></div>
                              <div class="custom-control custom-radio m-l-25">
                                   <input type="radio" class="custom-control-input" id="rbu4" name="rb_update_dp" value="4">
                                   <label class="custom-control-label" for="rbu4"> </label>
                               </div>
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_update_name" class="col-sm-3 text-left control-label col-form-label">Name:</label>
                           <div class="col-sm-9">
                              <input type="text" class="form-control" id="tb_update_name">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_update_uname" class="col-sm-3 text-left control-label col-form-label">Username:</label>
                           <div class="col-sm-9">
                              <input type="text" class="form-control" id="tb_update_uname" disabled>
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_update_mail" class="col-sm-3 text-left control-label col-form-label">Email:</label>
                           <div class="col-sm-9">
                              <input type="text" class="form-control" id="tb_update_mail">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_update_new_pwd" class="col-sm-3 text-left control-label col-form-label">New Password:</label>
                           <div class="col-sm-9">
                              <input type="password" class="form-control" id="tb_update_new_pwd" placeholder="New Password Here" oninput="renderPwdStrength(this.value, '#pwd_strength_settings')">
                              <div id="pwd_strength_settings" class="pwd-strength-meter mt-1" style="display:none;">
                                 <div class="pwd-strength-bar" style="height:4px;border-radius:2px;background:#e9ecef;overflow:hidden;">
                                    <div class="pwd-strength-fill" style="height:100%;width:0%;transition:width .15s ease, background .15s ease;"></div>
                                 </div>
                                 <small class="pwd-strength-label text-muted"></small>
                              </div>
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="tb_update_confirm_pwd" class="col-sm-3 text-left control-label col-form-label">Confirm Password:</label>
                           <div class="col-sm-9">
                              <input type="password" class="form-control" id="tb_update_confirm_pwd" placeholder="Confirm Password Here">
                           </div>
                        </div>
                        <hr/>
                        <div class="form-group row">
                           <label for="tb_update_current_pwd" class="col-sm-3 text-left control-label col-form-label">Your Password:</label>
                           <div class="col-sm-9">
                              <input type="password" class="form-control" id="tb_update_current_pwd" placeholder="Your Password Here">
                           </div>
                        </div>
                     </div>
                     <div class="modal-footer" >
                        <button type="button" class="btn btn-success" onclick="modifyUserAction($(this))"><i class="fa fas fa-save"></i> Update</button>
                     </div>
                  </div>
               </div>
            </div>
         </div>
         <!-- ============================================================== -->
         <!-- End Page wrapper  -->
         <!-- ============================================================== -->
      </div>
      <!-- ============================================================== -->
      <!-- End Wrapper -->
      <!-- ============================================================== -->
      <!-- ============================================================== -->
      <!-- All Jquery -->
      <!-- ============================================================== -->
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/js.cookie.min.js"></script>
      <!-- Bootstrap tether Core JavaScript -->
      <script src="js/libs/popper.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <!--Custom JavaScript -->
      <script src="js/libs/custom.min.js"></script>
      <script src="js/libs/select2.min.js"></script>
      <script src="js/libs/moment.min.js"></script>
      <script src="js/libs/moment-timezone-with-data.min.js"></script>
      <script src="js/libs/jquery/datatables.js"></script>  
      <script src="js/common_scripts.js"></script>
      <script src="js/settings_user.js"></script>
      <script type="text/javascript">
         var curr_version = "<?php getSniperPhishVersion(); ?>";
         $("#lb_version").text("Version: " + curr_version);
      </script>
      
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>