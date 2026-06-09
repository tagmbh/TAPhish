<?php
   require_once(dirname(__FILE__,2) . '/manager/session_manager.php');
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
      <link rel="icon" type="image/png" sizes="16x16" href="../images/favicon.png">
      <title>TAPhish - Web-Email Spear Phishing Toolkit</title>
      <!-- Custom CSS -->      
      <link rel="stylesheet" type="text/css" href="../css/select2.min.css">
      <link rel="stylesheet" type="text/css" href="../css/summernote-lite.min.css">
      <link rel="stylesheet" type="text/css" href="../css/style.min.css">
      <link rel="stylesheet" type="text/css" href="../css/brand.css">
      <link rel="stylesheet" type="text/css" href="../css/dataTables.foundation.min.css">
      <style> 
         .tab-header{ list-style-type: none; }
      </style>
      <link rel="stylesheet" type="text/css" href="../css/toastr.min.css">
      <link rel="stylesheet" type="text/css" href="css/sniperhoststyle.min.css">
      <link rel="stylesheet" type="text/css" href="../css/codemirror.min.css">
      <link rel="stylesheet" type="text/css" href="../css/prism.css"/>
   </head>
   <body>
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
         <?php include_once(dirname(__FILE__,2) . '/z_menu.php'); ?>
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
            <div class="page-breadcrumb breadcrumb-withbutton">
               <div class="row">
                  <div class="col-12 d-flex no-block align-items-center">
                     <h4 class="page-title">Landing Page Hosting</h4>
                     <div class="ml-auto text-right">
                        <span class="badge badge-dark" data-toggle="tooltip" title="Landing page ID" id="lb_lp_id"></span>                          
                        <button type="button" class="btn btn-info btn-sm" onclick="window.location = window.location.pathname;"><i class="fa fas fa-plus" title="New hosting" data-toggle="tooltip" data-placement="bottom"></i></button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- ============================================================== -->
            <!-- End Bread crumb and right sidebar toggle -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- Container fluid  -->
            <!-- ============================================================== -->
            <div class="container-fluid" >
               <!-- ============================================================== -->
               <!-- Start Page Content -->
               <!-- ============================================================== -->
               <div class="row">
                  <div class="col-12">
                     <div class="card">
                        <div class="card-body">
                           <div class="row">
                              <div class="col-md-4">
                                 <div class="form-group">
                                    <label>Page Name:</label>
                                    <input type="text" class="form-control date-inputmask" id="tb_page_name" placeholder="My page">
                                 </div>
                              </div>
                              <div class="col-md-4">
                                 <div class="form-group">
                                    <label>Page File Name:</label>
                                    <input type="text" class="form-control date-inputmask" id="tb_page_file_name" placeholder="mypage.html">
                                 </div>
                              </div>
                              <div class="col-md-4 align-items-right text-right float-right">
                                 <button type="button" class="btn btn-warning mr-2" data-toggle="modal" data-target="#modal_ai_landing" title="Generate page HTML with Claude API"><i class="fas fa-robot"></i> AI Generate</button>
                                 <button type="button" class="btn btn-info" onclick="saveLandPage($(this))"><i class="fa fas fa-save"></i> Save</button>
                              </div>
                           </div>

                           <div class="row m-t-10">
                              <div class="col-md-12 m-t-10">
                                 <div class="form-group">
                                    <textarea id="summernote"></textarea>
                                 </div>
                              </div>
                           </div>

                           <div class="row">
                              <div class="col-md-12">
                                 <div class="form-group">                                    
                                    <div class="row">
                                       <label for="tb_pl_name" class="col-md-1 text-left control-label col-form-label">Direct access link:</label>
                                       <div class="col-md-12">
                                          <div class="col-md-12 prism_side-top">
                                            <span><button type="button" class="btn waves-effect waves-light btn-xs btn-dark mdi mdi-content-copy btn_copy" data-toggle="tooltip" title="Copy" onclick="copyCode($(this),'code_class_link')"/><button type="button" class="btn waves-effect waves-light btn-xs btn-dark mdi mdi-reload" data-toggle="tooltip" title="Re-generate access link" onClick="generateAccessLink($('#file_extension_selector').val())"/></span>
                                          </div>
                                          <pre class="code_class_link"><code class="language-shell" id="link_output"> </code></pre>
                                       </div>
                                   </div>
                                 </div>
                              </div>                              
                           </div>

                           <div class="row">
                              <div class="col-md-12 m-t-20">
                                 <div class="table-responsive">
                                    <table id="table_landpage_list" class="table table-striped table-bordered">
                                       <thead>
                                          <tr>
                                             <th>#</th>
                                             <th>Page Name</th>
                                             <th>Page File Name</th>
                                             <th>Date Created</th>
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
            <!-- End Container fluid  -->
            <!-- ============================================================== -->
            <!-- ============================================================== -->
            <!-- footer -->
            <!-- ============================================================== -->
            <!-- Modal -->
            <div class="modal fade" id="modal_lp_delete" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Are you sure?</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        This will delete the landing page and the action can't be undone!
                     </div>
                     <div class="modal-footer" >
                        <button type="button" class="btn btn-danger" onclick="landPageDeletionAction()">Delete</button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Modal -->
            <div class="modal fade" id="modal_web_tracker_selection" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">Link Web Tracker</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        <div class="form-group row m-t-20">
                           <label for="web_tracker_selector" class="col-sm-4  control-label col-form-label">Select web tracker:</label>
                           <div class="col-md-8">
                              <select class="select2 form-control " id="web_tracker_selector" style="height: 36px;width: 100%;">
                              </select>
                           </div>
                        </div>
                        <div class="form-group row m-t-20">
                           <label for="web_tracker_style_selector" class="col-sm-4  control-label col-form-label">Display style:</label>
                           <div class="col-md-7">
                              <select class="select2 form-control " id="web_tracker_style_selector" style="height: 36px;width: 100%;">
                                 <option value="1">Style 1 - with {{RID}}</option>
                                 <option value="2">Style 2 - no {{RID}}</option>
                                 <option value="3">Style 3 - with text</option>
                              </select>
                           </div>
                           <i class="mdi mdi-information cursor-pointer m-t-5" tabindex="0" data-toggle="popover" data-trigger="focus" data-placement="top" data-content="Link display style only. You may customize display text from the code view of editor" data-original-title="" title=""></i>
                        </div>
                     </div>
                     <div class="modal-footer" >
                        <button type="button" class="btn btn-success" onclick="linkWebTracker()"><i class="fa fas  fa-plus"></i> Link</button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Modal -->
            <div class="modal fade" id="modal_media_link" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-body">
                        <div class="form-group row m-t-20">
                           <label for="modal_media_link_text" class="col-sm-2 control-label col-form-label">Text:</label>
                           <div class="col-sm-10">
                              <input type="text" class="form-control" id="modal_media_link_text">
                           </div>
                        </div>
                        <div class="form-group row  m-t-20">
                           <label for="modal_media_link_url" class="col-sm-2 control-label col-form-label">URL:</label>
                           <div class="col-sm-10">
                              <input type="text" class="form-control" id="modal_media_link_url" placeholder="https://">
                           </div>
                        </div>
                     </div>
                     <div class="modal-footer" >
                        <button type="button" class="btn btn-success" onclick="insertMedia('link')"><i class="mdi mdi-arrow-bottom-left"></i> Insert</button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Modal -->
            <div class="modal fade" id="modal_media_pic" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-body">
                        <div class="form-group row m-t-20">
                           <label for="modal_media_pic_url" class="col-sm-2 control-label col-form-label">Link:</label>
                           <div class="col-sm-10">
                              <input type="text" class="form-control" id="modal_media_pic_url" placeholder="https://">
                           </div>
                        </div>
                     </div>
                     <div class="modal-footer col-md-12">
                        <button type="button" class="btn btn-success" onclick="insertMedia('pic')"><i class="mdi mdi-arrow-bottom-left"></i> Insert</button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Modal -->
            <div class="modal fade" id="modal_media_video" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog" role="document">
                  <div class="modal-content">
                     <div class="modal-body">
                        <div class="form-group row m-t-20">
                           <label for="modal_media_video_url" class="col-sm-2 control-label col-form-label">Link:</label>
                           <div class="col-sm-10">
                              <input type="text" class="form-control" id="modal_media_video_url" placeholder="https://">
                           </div>
                        </div>
                     </div>
                     <div class="modal-footer" >
                        <button type="button" class="btn btn-success" onclick="insertMedia('video')"><i class="mdi mdi-arrow-bottom-left"></i> Insert</button>
                     </div>
                  </div>
               </div>
            </div>
            <!-- AI landing-page generator (Phase 3.17) -->
            <div class="modal fade" id="modal_ai_landing" tabindex="-1" role="dialog" aria-hidden="true">
               <div class="modal-dialog modal-lg" role="document">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title">AI landing-page generator</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                     </div>
                     <div class="modal-body">
                        <p class="text-muted small">For authorized engagements only. Generated HTML will replace the current page content. API key is kept in your browser localStorage and sent on each request; it is not persisted server-side.</p>
                        <div class="form-group row">
                           <label for="ai_landing_apikey" class="col-sm-3 control-label col-form-label">Anthropic API key</label>
                           <div class="col-sm-9">
                              <input type="password" class="form-control" id="ai_landing_apikey" placeholder="sk-ant-...">
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="ai_landing_model" class="col-sm-3 control-label col-form-label">Model</label>
                           <div class="col-sm-9">
                              <select class="form-control" id="ai_landing_model">
                                 <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5 (fast, cheap — default)</option>
                                 <option value="claude-sonnet-4-6">Claude Sonnet 4.6 (balanced)</option>
                                 <option value="claude-opus-4-8">Claude Opus 4.8 (most capable)</option>
                                 <option value="claude-sonnet-4-5">Claude Sonnet 4.5</option>
                                 <option value="claude-opus-4-5">Claude Opus 4.5</option>
                              </select>
                           </div>
                        </div>
                        <div class="form-group row">
                           <label for="ai_landing_prompt" class="col-sm-3 control-label col-form-label">Describe the page</label>
                           <div class="col-sm-9">
                              <textarea class="form-control" id="ai_landing_prompt" rows="5" placeholder="e.g. A corporate IT password reset confirmation page with a centered card, blue accent color, a single password and confirm-password field, and a Submit button."></textarea>
                           </div>
                        </div>
                        <div id="ai_landing_summary" class="text-muted small"></div>
                     </div>
                     <div class="modal-footer">
                        <button type="button" class="btn btn-info" onclick="aiLandingGenerate($(this))"><i class="fas fa-robot"></i> Generate &amp; load into editor</button>
                     </div>
                  </div>
               </div>
            </div>
            <?php include_once '../z_footer.php' ?>
            <!-- ============================================================== -->
            <!-- End footer -->
            <!-- ============================================================== -->
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
      <script src="../js/libs/jquery/jquery-3.6.0.min.js"></script> 
      <script src="../js/libs/jquery/jquery-ui.min.js"></script>
      <script src="../js/libs/js.cookie.min.js"></script>
      <script src="../js/libs/perfect-scrollbar.jquery.min.js"></script>
      <script src="../js/libs/custom.min.js"></script>
      <!-- this page js -->
      <script src="../js/libs/clipboard.min.js"></script> 
      <script src="../js/libs/summernote-bs4.min.js"></script>
      <script src="../js/libs/codemirror.min.js"></script>
      <script src="../js/common_scripts.js"></script>  
      <script src="js/sniper_landing_page.js"></script>
      <?php
         echo '<script>';
         if(isset($_GET['lp']))
               echo '$("#section_view_list").hide();
                     viewLandPageDetailsFromId("' . doFilter($_GET['lp'],'ALPHA_NUM') . '",true);';
         
         echo '</script>';
      ?>   
      <script defer src="../js/libs/select2.min.js"></script>
      <script defer src="../js/libs/jquery/datatables.js"></script>   
      <script defer src="../js/libs/prism.js"></script>
      <script defer src="../js/libs/moment.min.js"></script>
      <script defer src="js/shell.min.js"></script>
      <script defer src="../js/libs/sidebarmenu.js"></script>
      <script defer src="../js/libs/popper.min.js"></script>
      <script defer src="../js/libs/bootstrap.min.js"></script>
      <script defer src="../js/libs/toastr.min.js"></script>
   </body>
</html>