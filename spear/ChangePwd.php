<?php
   $_db_file = dirname(__FILE__) . '/config/db.php';
   if (!file_exists($_db_file)) {
      header('Location: /install');
      exit;
   }
   require_once($_db_file);
   require_once(dirname(__FILE__) . '/manager/common_functions.php');
   if(isset($_GET['token'])){
      if(!isTokenValid($conn,$_GET['token']))
        die("Incorrect request. Token may be invalid");
   }
   else
      die();
?>
<!DOCTYPE html>
<html dir="ltr">
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
      <link rel="stylesheet" type="text/css" href="css/style.min.css">
      <link rel="stylesheet" type="text/css" href="css/brand.css">
   </head>
   <body class="dim-panel">
      <div class="main-wrapper">
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
         <!-- Preloader - style you can find in spinners.css -->
         <!-- ============================================================== -->
         <!-- ============================================================== -->
         <!-- Login box.scss -->
         <!-- ============================================================== -->
         <div class=" d-flex no-block justify-content-center align-items-center bg-dark">
            <div class="bg-dark border-top border-secondary">
               <div class="text-center p-t-20 p-b-20">
                  <span class="db"><img src="images/brand/logo-icon2x.png" alt="logo" /><img src="images/brand/logo.png" alt="logo" /></span>
               </div>
            </div>
         </div>
         <div class="auth-wrapper d-flex no-block justify-content-center align-items-center bg-dark req-box">
            <div class="auth-box bg-dark req-box">
               <form class="form-horizontal m-t-20" id="doPwdReset">
                  <div class="row border-top border-secondary">
                     <div class="col-12">
                        <div class="form-group p-t-20">
                           <div id="inst_fields">
                              <div class="input-group mb-3">
                                 <div class="input-group-prepend">
                                    <span class="input-group-text bg-info text-white" id="basic-addon1"><i class="fa fas fa-key"></i></span>
                                 </div>
                                 <input type="password" class="form-control form-control-lg" placeholder="New Password" id="tb_pwd" aria-label="Username" aria-describedby="basic-addon1" required oninput="renderPwdStrength(this.value, '#pwd_strength_reset')">
                              </div>
                              <div id="pwd_strength_reset" class="pwd-strength-meter mb-3" style="display:none;">
                                 <div class="pwd-strength-bar" style="height:4px;border-radius:2px;background:rgba(255,255,255,0.15);overflow:hidden;">
                                    <div class="pwd-strength-fill" style="height:100%;width:0%;transition:width .15s ease, background .15s ease;"></div>
                                 </div>
                                 <small class="pwd-strength-label text-muted"></small>
                              </div>
                              <div class="input-group mb-3">
                                 <div class="input-group-prepend">
                                    <span class="input-group-text bg-info text-white" id="basic-addon2"><i class="fa fas fa-key"></i></span>
                                 </div>
                                 <input type="password" class="form-control form-control-lg" placeholder="Confirm Password" id="tb_pwd_confirm" aria-label="Password" aria-describedby="basic-addon1" required>
                              </div>                              
                           </div>
                        </div>
                     </div>
                  </div>
                  <div class="row border-top border-secondary">
                     <div class="col-12">
                        <div class="form-group">
                           <div class="p-t-20">
                              <button class="btn btn-info float-right" id="bt_reset_pwd" type="submit"><i class="fa fas"></i> Change</button>
                           </div>
                        </div>
                     </div>
                     <div id="lb_msg" class="m-t-10"></div>
                  </div>
              </form>
            </div>
         </div>
      </div>
       <div class="auth-wrapper  bg-dark">
            
       </div>
      <!-- ============================================================== -->
      <!-- All Required js -->
      <!-- ============================================================== -->
      <script src="js/libs/jquery/jquery-3.6.0.min.js"></script>
      <!-- Bootstrap tether Core JavaScript -->
      <!-- ============================================================== -->
      <!-- This page plugin js -->
      <!-- ============================================================== -->
      <script>
        $(".preloader").fadeOut();
        // ==============================================================
        // Phase 3.22: inline copy of common_scripts.js's strength scorer
        // (ChangePwd runs pre-session and intentionally does not load
        // common_scripts.js).
        function scorePasswordStrength(pw){if(!pw)return 0;var s=0;if(pw.length>=8)s++;if(pw.length>=12)s++;if(pw.length>=16)s++;if(/[a-z]/.test(pw)&&/[A-Z]/.test(pw))s++;if(/[0-9]/.test(pw))s++;if(/[^A-Za-z0-9]/.test(pw))s++;var l=pw.toLowerCase();var b=["password","123456","sniperphish","qwerty","letmein","admin","welcome","passw0rd"];for(var i=0;i<b.length;i++){if(l.indexOf(b[i])!==-1){s=Math.max(0,s-3);break;}}return Math.max(0,Math.min(4,s-2));}
        function renderPwdStrength(pw,sel){var $w=$(sel);if(!$w.length)return;if(!pw){$w.hide();return;}$w.show();var s=scorePasswordStrength(pw);var p=[10,30,55,80,100];var c=["#dc3545","#fd7e14","#ffc107","#198754","#0d6efd"];var n=["Very weak","Weak","OK","Strong","Excellent"];$w.find(".pwd-strength-fill").css({width:p[s]+"%",background:c[s]});$w.find(".pwd-strength-label").text(n[s]).css("color",c[s]);}


        $("#doPwdReset").submit(function(event) {
            event.preventDefault();

            if($("#tb_pwd").val() != $("#tb_pwd_confirm").val()){
              $("#lb_msg").html('<span class="text-danger">Passwords are not matching.</span>');
              return;
            }

            if($("#tb_pwd").val().length <8 ){
              $("#lb_msg").html('<span class="text-danger">Password minimum length 8 is required.</span>');
              return;
            }

            $("#bt_reset_pwd i").toggleClass('fa-spinner fa-spin');
            $.post({
                url: "pwd_manager",
                contentType: 'application/json; charset=utf-8',
                data: JSON.stringify({ 
                    action_type: "do_change_pwd",
                    new_pwd: $("#tb_pwd").val(),
                    token: location.search.split("?token=")[1],
                })
            }).done(function (data) {
                $("#bt_reset_pwd i").toggleClass('fa-spinner fa-spin');
                if(data.result == "success"){ 
                    $("#lb_msg").html('<span class="text-success">Password reset successs. Click <a href="/spear">here</a> to login</span>');
                }
                else
                    $("#lb_msg").html('<span class="text-danger">' + data.error + '</span>');
              });
        });
      </script>
   </body>
</html>