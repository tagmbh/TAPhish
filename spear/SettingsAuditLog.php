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
      <title>TAPhish — Audit log</title>
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
      <link rel="stylesheet" type="text/css" href="css/toastr.min.css">
   </head>
   <body class="dim-panel">
      <div class="preloader"><div class="lds-ripple"><div class="lds-pos"></div><div class="lds-pos"></div></div></div>
      <div id="main-wrapper">
         <?php include_once 'z_menu.php' ?>
         <div class="page-wrapper">
            <div class="page-breadcrumb">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">Audit log</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <!-- Filters -->
               <div class="card mb-3">
                  <div class="card-body">
                     <h5 class="card-title">Filters</h5>
                     <div class="form-row">
                        <div class="form-group col-md-2">
                           <label>Kind</label>
                           <select class="form-control form-control-sm" id="f_kind">
                              <option value="">(any)</option>
                              <option>AUTH</option><option>CAMP</option><option>RECP</option>
                              <option>TMPL</option><option>SEND</option><option>SCAN</option>
                              <option>CAPT</option><option>ENGM</option><option>CLON</option>
                              <option>BEEF</option><option>SYS</option>
                           </select>
                        </div>
                        <div class="form-group col-md-2">
                           <label>Severity</label>
                           <select class="form-control form-control-sm" id="f_severity">
                              <option value="">(any)</option>
                              <option value="ok">ok</option>
                              <option value="warn">warn</option>
                              <option value="error">error</option>
                           </select>
                        </div>
                        <div class="form-group col-md-2">
                           <label>Username</label>
                           <input type="text" class="form-control form-control-sm" id="f_username" placeholder="admin">
                        </div>
                        <div class="form-group col-md-2">
                           <label>From (UTC)</label>
                           <input type="date" class="form-control form-control-sm" id="f_date_from">
                        </div>
                        <div class="form-group col-md-2">
                           <label>To (UTC)</label>
                           <input type="date" class="form-control form-control-sm" id="f_date_to">
                        </div>
                        <div class="form-group col-md-2 align-self-end">
                           <button class="btn btn-info btn-sm btn-block" id="btn_query">
                              <i class="fa fa-search"></i> Query
                           </button>
                        </div>
                     </div>
                     <div class="form-row">
                        <div class="form-group col-md-10">
                           <label>Search (substring)</label>
                           <input type="text" class="form-control form-control-sm" id="f_search" placeholder="campaign Q3">
                        </div>
                        <div class="form-group col-md-2 align-self-end">
                           <a href="#" class="btn btn-outline-secondary btn-sm btn-block" id="btn_export_csv">
                              <i class="fa fa-file-csv"></i> Export CSV
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
               <!-- Results -->
               <div class="card">
                  <div class="card-body">
                     <div class="d-flex align-items-baseline mb-2">
                        <h5 class="card-title mb-0">Entries</h5>
                        <span class="small text-muted ml-auto" id="result_summary">—</span>
                     </div>
                     <div class="table-responsive">
                        <table class="table table-sm table-striped" id="tbl_audit">
                           <thead>
                              <tr>
                                 <th style="white-space:nowrap">When (UTC)</th>
                                 <th>User</th>
                                 <th>IP</th>
                                 <th>Kind</th>
                                 <th>Sev</th>
                                 <th>Message</th>
                              </tr>
                           </thead>
                           <tbody><tr><td colspan="6" class="text-muted small text-center">Run a query to see entries.</td></tr></tbody>
                        </table>
                     </div>
                     <div class="d-flex">
                        <button class="btn btn-light btn-sm" id="btn_prev" disabled><i class="fa fa-chevron-left"></i> Prev</button>
                        <button class="btn btn-light btn-sm ml-2" id="btn_next" disabled>Next <i class="fa fa-chevron-right"></i></button>
                        <span class="text-muted small ml-auto align-self-center" id="page_indicator">Page 1</span>
                     </div>
                  </div>
               </div>
            </div>
            <?php include_once 'z_footer.php' ?>
         </div>
      </div>
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <script src="js/libs/js.cookie.min.js"></script>
      <script src="js/libs/bootstrap.min.js"></script>
      <script src="js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="js/libs/custom.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/settings_audit_log.js"></script>
      <script defer src="js/libs/sidebarmenu.js"></script>
      <script defer src="js/libs/toastr.min.js"></script>
   </body>
</html>
