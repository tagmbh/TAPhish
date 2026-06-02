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
      <title>TAPhish — Quick Start</title>
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
                     <h4 class="page-title">Quick Start Wizard</h4>
                  </div>
               </div>
            </div>
            <div class="container-fluid">
               <div class="row">
                  <div class="col-lg-7 mb-4">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Step 1 — Engagement metadata</h5>
                           <p class="text-muted small">
                              Name the engagement, list the email domains the customer authorised
                              you to phish, and set the engagement window. This becomes the parent
                              record every later step (OSINT, sender, recipients, landing page,
                              send) anchors to.
                           </p>
                           <form id="frm_engagement">
                              <div class="form-group">
                                 <label>Engagement name <span class="text-danger">*</span></label>
                                 <input type="text" class="form-control" id="eng_name" placeholder="Acme Q3 Awareness" required minlength="3" maxlength="160">
                                 <small class="form-text text-muted">Becomes the slug used in URLs and report filenames.</small>
                              </div>
                              <div class="form-group">
                                 <label>Target organisation</label>
                                 <input type="text" class="form-control" id="eng_org" placeholder="Acme Bank" maxlength="160">
                              </div>
                              <div class="form-row">
                                 <div class="form-group col-md-6">
                                    <label>Window start (UTC) <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="eng_start" required>
                                 </div>
                                 <div class="form-group col-md-6">
                                    <label>Window end (UTC) <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="eng_end" required>
                                 </div>
                              </div>
                              <div class="form-group">
                                 <label>Authorised email domains <span class="text-danger">*</span></label>
                                 <textarea class="form-control" id="eng_scope" rows="4" placeholder="acme.com&#10;hr.acme.com&#10;vendor.example.org" required></textarea>
                                 <small class="form-text text-muted">One domain per line (commas or spaces also fine). Sub-domains are covered automatically by their parent (acme.com covers payroll.acme.com).</small>
                              </div>
                              <div class="form-group">
                                 <label>Notes</label>
                                 <textarea class="form-control" id="eng_notes" rows="2" placeholder="Authorised by ticket #4521, scope letter on file" maxlength="2000"></textarea>
                              </div>
                              <button type="submit" class="btn btn-info" id="btn_save_eng">
                                 <i class="fa fa-save"></i> Save engagement
                              </button>
                              <a class="btn btn-link" href="/spear/Home">Cancel</a>
                           </form>
                           <div id="eng_result" class="mt-3"></div>
                        </div>
                     </div>
                  </div>
                  <div class="col-lg-5 mb-4">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title d-flex">
                              Recent engagements
                              <button id="btn_refresh_eng" class="btn btn-sm btn-secondary ml-auto" type="button">
                                 <i class="fa fa-sync"></i>
                              </button>
                           </h5>
                           <table class="table table-sm table-striped" id="tb_engagements">
                              <thead><tr><th>Slug</th><th>Window</th><th>Scope</th><th>Status</th></tr></thead>
                              <tbody></tbody>
                           </table>
                        </div>
                     </div>
                     <div class="card mt-3">
                        <div class="card-body">
                           <h6 class="card-title">What's next</h6>
                           <p class="text-muted small mb-2">Phase 3.43a ships Step 1. Subsequent slices add:</p>
                           <ol class="small mb-0 pl-3">
                              <li>OSINT pre-check (SPF/DMARC/MX, homoglyph, subdomain enum)</li>
                              <li>Pretext picker filtered by detected tech stack</li>
                              <li>Sender setup with DKIM key gen + SMTP probe</li>
                              <li>Recipient upload + per-domain scope check</li>
                              <li>Landing page (clone / AI / library)</li>
                              <li>Pre-flight gates + send/schedule</li>
                           </ol>
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
      <script src="js/quick_start.js"></script>
   </body>
</html>
