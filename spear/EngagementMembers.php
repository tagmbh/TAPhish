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
                     <h4 class="page-title">Engagement Members</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <input type="hidden" id="eng_members_id" value="<?php echo (int) $eid; ?>">
               <?php if ($eid <= 0): ?>
                  <div class="alert alert-warning alert-rounded">
                     No engagement selected. Open an engagement from
                     <a href="EngagementView">Engagements</a> and use <strong>Manage members</strong>.
                  </div>
               <?php else: ?>
               <div class="card">
                  <div class="card-body">
                     <h5 class="card-title">
                        <i class="fas fa-users mr-2"></i>Members of <span id="eng_members_name">engagement #<?php echo (int) $eid; ?></span>
                     </h5>
                     <p class="text-muted small">
                        Membership is the visibility boundary for this engagement &mdash; only members can view its
                        recipients and run its campaigns. <strong>Owners</strong> can manage the roster and transition
                        status; <strong>members</strong> operate; <strong>read-only</strong> can look but not change.
                        An engagement always keeps at least one owner.
                     </p>
                     <div class="form-group row align-items-end">
                        <div class="col-sm-5">
                           <label for="tb_member_username">Username</label>
                           <input type="text" class="form-control" id="tb_member_username" placeholder="existing account username">
                        </div>
                        <div class="col-sm-4">
                           <label for="sel_member_role">Engagement role</label>
                           <select class="form-control" id="sel_member_role">
                              <option value="member" selected>Member</option>
                              <option value="owner">Owner</option>
                              <option value="read-only">Read-only</option>
                           </select>
                        </div>
                        <div class="col-sm-3">
                           <button type="button" class="btn btn-info" onclick="addMemberAction($(this))"><i class="fas fa-user-plus"></i> Add member</button>
                        </div>
                     </div>
                  </div>
               </div>

               <div class="card">
                  <div class="card-body">
                     <div class="table-responsive">
                        <table id="table_member_list" class="table table-striped table-bordered">
                           <thead>
                              <tr>
                                 <th>#</th>
                                 <th>Username</th>
                                 <th>Name</th>
                                 <th>Global role</th>
                                 <th>Engagement role</th>
                                 <th>Actions</th>
                              </tr>
                           </thead>
                           <tbody></tbody>
                        </table>
                     </div>
                  </div>
               </div>
               <?php endif; ?>
            </div>

            <?php include_once 'z_footer.php' ?>

            <!-- Remove confirmation -->
            <div class="modal fade" id="ModalMemberRemove" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Remove this member?</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        <span id="member_remove_name"></span> will lose access to this engagement's recipients and campaigns.
                     </div>
                     <div class="modal-footer">
                        <button type="button" class="btn btn-danger" onclick="removeMemberAction()">Remove</button>
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
      <script src="js/engagement_members.js"></script>
      <script type="text/javascript">
         var curr_version = "<?php getSniperPhishVersion(); ?>";
         $("#lb_version").text("Version: " + curr_version);
      </script>
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>
