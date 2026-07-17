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
      <link rel="icon" type="image/png" sizes="16x16" href="images/brand/favicon.png">
      <title>TAPhish - Trackers</title>
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
         <?php include_once 'z_menu.php' ?>
         <div class="page-wrapper">
            <div class="page-breadcrumb">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">Trackers</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="card">
                  <div class="card-body">
                     <div class="row align-items-center">
                        <div class="col-md-6">
                           <button type="button" class="btn btn-info btn-sm" onclick="document.location='TrackerGenerator';"><i class="fas fa-plus"></i> New Web Tracker</button>
                           <button type="button" class="btn btn-info btn-sm" onclick="document.location='QuickTracker';"><i class="fas fa-plus"></i> New Quick Tracker</button>
                        </div>
                        <div class="col-md-6 text-md-right mt-2 mt-md-0">
                           <div class="btn-group btn-group-sm" role="group" aria-label="Filter by type" id="tracker_type_filter">
                              <button type="button" class="btn btn-outline-secondary active" data-type="">All</button>
                              <button type="button" class="btn btn-outline-secondary" data-type="web">Web</button>
                              <button type="button" class="btn btn-outline-secondary" data-type="quick">Quick</button>
                           </div>
                        </div>
                     </div>

                     <div class="row">
                        <div class="col-md-12 m-t-20">
                           <div class="table-responsive">
                              <table id="table_all_trackers" class="table table-striped table-bordered">
                                 <thead>
                                    <tr>
                                       <th>#</th>
                                       <th>Type</th>
                                       <th>Tracker ID</th>
                                       <th>Name</th>
                                       <th>Status</th>
                                       <th>Engagement</th>
                                       <th>Date Created</th>
                                       <th>Actions</th>
                                    </tr>
                                 </thead>
                                 <tbody></tbody>
                              </table>
                           </div>
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
      <script src="js/libs/moment.min.js"></script>
      <script src="js/libs/moment-timezone-with-data.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/trackers_unified.js"></script>
      <script defer src="js/libs/jquery/datatables.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
      <?php include_once 'z_navboot.php' ?>
   </body>
</html>
