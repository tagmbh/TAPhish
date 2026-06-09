<?php
   require_once(dirname(__FILE__) . '/manager/session_manager.php');
   require_once(dirname(__FILE__) . '/manager/engagement.php');
   isSessionValid(true);

   // Phase 3.56: resumable wizard. Opened as ?engagement_id=N, we load the
   // saved step + non-secret state so the stepflow controller jumps straight
   // back to where the operator left off.
   $resume = ['id' => 0, 'step' => 1, 'state' => '{}'];
   $eid = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
   if ($eid > 0 && isset($conn) && $conn instanceof mysqli) {
      $resume = taphish_wizard_resume_payload(taphish_engagement_get_by_id($conn, $eid));
   }
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
   <head>
      <meta charset="utf-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link rel="icon" type="image/png" sizes="16x16" href="images/brand/favicon.png">
      <title>TAPhish — Quick Start</title>
      <link rel="stylesheet" type="text/css" href="css/summernote-lite.min.css">
      <link rel="stylesheet" type="text/css" href="css/codemirror.min.css">
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
               <ol class="t-stepper" id="t_stepper" aria-label="Wizard progress">
                  <li data-step="1" class="is-active">Engagement</li>
                  <li data-step="2">OSINT</li>
                  <li data-step="3">Recipients</li>
                  <li data-step="4">Landing</li>
                  <li data-step="5">Mail</li>
                  <li data-step="6">Sender</li>
                  <li data-step="7">Launch</li>
               </ol>
               <input type="hidden" id="wizard_engagement_id" value="<?php echo (int) $resume['id']; ?>">
               <input type="hidden" id="wizard_resume_step" value="<?php echo (int) $resume['step']; ?>">
               <input type="hidden" id="wizard_resume_state" value="<?php echo htmlspecialchars($resume['state'], ENT_QUOTES); ?>">
               <div class="row step-wrap" id="step1_wrap">
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
                                    <small class="form-text text-muted">Click the field to pick a date &amp; time. Pre-filled to now.</small>
                                 </div>
                                 <div class="form-group col-md-6">
                                    <label>Window end (UTC) <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="eng_end" required>
                                    <small class="form-text text-muted">Must be after the start. Pre-filled to +14 days.</small>
                                 </div>
                              </div>
                              <div class="form-group">
                                 <label>Authorised email domains <span class="text-danger">*</span></label>
                                 <textarea class="form-control" id="eng_scope" rows="3" placeholder="acme.com, hr.acme.com, vendor.example.org" required></textarea>
                                 <small class="form-text text-muted">Type or paste the domains the customer authorised you to phish — separated by comma, space, or new line. Sub-domains are covered automatically (<code>acme.com</code> also covers <code>payroll.acme.com</code>).</small>
                                 <div id="eng_scope_chips" class="mt-2"></div>
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
                           <div class="table-responsive">
                              <table class="table table-sm table-striped" id="tb_engagements">
                                 <thead><tr><th>Slug</th><th>Window</th><th>Scope</th><th>Status</th><th></th></tr></thead>
                                 <tbody></tbody>
                              </table>
                           </div>
                        </div>
                     </div>
                     <div class="card mt-3">
                        <div class="card-body">
                           <h6 class="card-title">Wizard slices</h6>
                           <ol class="small mb-0 pl-3">
                              <li><strong>Step 1 — Engagement metadata</strong> <span class="badge badge-info">3.43a</span></li>
                              <li><strong>Step 2 — OSINT pre-check</strong> <span class="badge badge-info">3.43b</span></li>
                              <li>Step 3 — Pretext picker filtered by tech stack <span class="badge badge-secondary">soon</span></li>
                              <li>Step 4 — Sender setup with DKIM gen + SMTP probe <span class="badge badge-secondary">soon</span></li>
                              <li>Step 5 — Recipient upload + per-domain scope check <span class="badge badge-secondary">soon</span></li>
                              <li>Step 6 — Landing page (clone / AI / library) <span class="badge badge-secondary">soon</span></li>
                              <li>Step 7 — Pre-flight gates + send / schedule <span class="badge badge-secondary">soon</span></li>
                           </ol>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- ============ Step 2 — OSINT pre-check ============ -->
               <div class="row step-wrap" id="step2_wrap">
                  <div class="col-12">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Step 2 — OSINT pre-check</h5>
                           <p class="text-muted small">
                              Drop the target's primary domain. The wizard fans out the
                              pre-engagement helpers TAPhish already ships and renders the
                              results side by side so you can shape the campaign accordingly.
                              All lookups are non-invasive (DNS + a tiny number of public
                              HTTPS fetches).
                           </p>
                           <div class="form-row align-items-end">
                              <div class="form-group col-md-6 mb-2">
                                 <label>Target domain</label>
                                 <input type="text" id="osint_domain" class="form-control" placeholder="acme.com" autocomplete="off">
                              </div>
                              <div class="form-group col-md-6 mb-2">
                                 <button class="btn btn-info" type="button" id="btn_osint_run">
                                    <i class="fa fa-search"></i> Run pre-check
                                 </button>
                                 <button class="btn btn-link" type="button" id="btn_osint_use_from_eng">
                                    Use first authorised domain from engagement
                                 </button>
                              </div>
                           </div>

                           <div class="row" id="osint_panel" style="display:none;">
                              <div class="col-md-6 col-lg-4 mb-3">
                                 <div class="card h-100">
                                    <div class="card-body">
                                       <h6 class="card-title">SPF / DMARC posture</h6>
                                       <div id="osint_dmarc" class="small">—</div>
                                    </div>
                                 </div>
                              </div>
                              <div class="col-md-6 col-lg-4 mb-3">
                                 <div class="card h-100">
                                    <div class="card-body">
                                       <h6 class="card-title">MX / tech stack</h6>
                                       <div id="osint_mx" class="small">—</div>
                                    </div>
                                 </div>
                              </div>
                              <div class="col-md-6 col-lg-4 mb-3">
                                 <div class="card h-100">
                                    <div class="card-body">
                                       <h6 class="card-title">Look-alike domains</h6>
                                       <div id="osint_homoglyph" class="small">—</div>
                                    </div>
                                 </div>
                              </div>
                              <div class="col-md-6 col-lg-4 mb-3">
                                 <div class="card h-100">
                                    <div class="card-body">
                                       <h6 class="card-title">Subdomain enum (crt.sh)</h6>
                                       <div id="osint_subdomains" class="small">—</div>
                                    </div>
                                 </div>
                              </div>
                              <div class="col-md-6 col-lg-4 mb-3">
                                 <div class="card h-100">
                                    <div class="card-body">
                                       <h6 class="card-title">Email format (Hunter)</h6>
                                       <div id="osint_hunter" class="small">—</div>
                                    </div>
                                 </div>
                              </div>
                              <div class="col-md-6 col-lg-4 mb-3">
                                 <div class="card h-100">
                                    <div class="card-body">
                                       <h6 class="card-title">Web fingerprint</h6>
                                       <div id="osint_web" class="small">—</div>
                                    </div>
                                 </div>
                              </div>
                              <!-- Phase 3.46-pre: Shodan host lookup. Operator
                                   API key is read from localStorage and sent
                                   inline so it never persists server-side. -->
                              <div class="col-md-6 col-lg-4 mb-3">
                                 <div class="card h-100">
                                    <div class="card-body">
                                       <h6 class="card-title">
                                          Exposed surface (Shodan)
                                          <a href="#" id="btn_shodan_key" class="small ml-2 text-muted" title="Paste / clear Shodan API key">key</a>
                                       </h6>
                                       <div id="osint_shodan" class="small">—</div>
                                    </div>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- ============ Step 3 — Recipients (commit) ============ -->
               <div class="row step-wrap" id="step3_wrap" style="display:none;">
                  <div class="col-12 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Step 3 — Recipients</h5>
                           <p class="text-muted small">
                              Paste your CSV (<code>First,Last,Email</code> or
                              <code>First,Email,Notes</code>), preview the scope check, then
                              <em>commit</em>. The wizard parses with the same engine the upload
                              page uses, drops out-of-scope rows automatically, and stores a real
                              recipient group on this engagement — no detour to Mail User Group.
                           </p>
                           <div class="form-group">
                              <label>Group name <span class="text-danger">*</span></label>
                              <input type="text" class="form-control" id="rcpt_group_name" placeholder="Acme HR — wave 1" maxlength="50">
                           </div>
                           <div class="form-group">
                              <label>Recipients CSV</label>
                              <textarea class="form-control" id="rcpt_csv" rows="6" placeholder="First,Last,Email&#10;Alice,Smith,alice@target.example"></textarea>
                           </div>
                           <button class="btn btn-secondary" type="button" id="btn_rcpt_preview">
                              <i class="fa fa-eye"></i> Preview
                           </button>
                           <button class="btn btn-info" type="button" id="btn_rcpt_commit">
                              <i class="fa fa-save"></i> Commit recipients
                           </button>
                           <div id="rcpt_preview_result" class="mt-3"></div>
                           <div id="rcpt_commit_result" class="mt-3"></div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- ============ Step 4 — Landing + Tracker (commit) ============ -->
               <div class="row step-wrap" id="step4_wrap" style="display:none;">
                  <div class="col-lg-6 mb-3">
                     <div class="card h-100">
                        <div class="card-body">
                           <h5 class="card-title">Step 4a — Web tracker</h5>
                           <p class="text-muted small">
                              The tracker captures visits + form submits and ships them to your
                              webhook. Pick an existing tracker, or let the wizard auto-create a
                              minimal one named after this engagement.
                           </p>
                           <div class="form-group">
                              <label>Existing trackers</label>
                              <select class="form-control" id="trk_select">
                                 <option value="">— loading… —</option>
                              </select>
                           </div>
                           <div class="form-row align-items-end">
                              <div class="form-group col-md-7 mb-2">
                                 <label>New tracker name</label>
                                 <input type="text" class="form-control" id="trk_new_name" placeholder="(auto from engagement)">
                              </div>
                              <div class="form-group col-md-5 mb-2">
                                 <button class="btn btn-info btn-block" type="button" id="btn_trk_create">
                                    <i class="fa fa-plus"></i> Create tracker
                                 </button>
                              </div>
                              <div class="form-group col-md-12 mb-2">
                                 <label>Webhook URL (optional)</label>
                                 <input type="text" class="form-control" id="trk_webhook" placeholder="(blank = this host /track.php)">
                              </div>
                           </div>
                           <div id="trk_result" class="small"></div>
                        </div>
                     </div>
                  </div>
                  <div class="col-lg-6 mb-3">
                     <div class="card h-100">
                        <div class="card-body">
                           <h5 class="card-title">Step 4b — Landing page</h5>
                           <p class="text-muted small">
                              Clone a real target page (the selected tracker is injected), or spin
                              up a curated library template. The resulting public URL becomes the
                              CTA target wired into your mail in Step 5.
                           </p>
                           <div class="form-group">
                              <label>Target URL to clone</label>
                              <input type="text" class="form-control" id="clone_url" placeholder="https://login.microsoftonline.com/">
                           </div>
                           <div class="form-group">
                              <label>Slug <span class="text-danger">*</span></label>
                              <input type="text" class="form-control" id="clone_slug" placeholder="m365-login">
                              <small class="form-text text-muted">Lowercase letters, digits and hyphens. Becomes part of the public URL.</small>
                           </div>
                           <button class="btn btn-info" type="button" id="btn_clone_site">
                              <i class="fa fa-clone"></i> Clone site
                           </button>
                           <hr>
                           <div class="form-group mb-1">
                              <label class="small text-muted mb-1">Or clone a library template</label>
                              <select class="form-control form-control-sm" id="lib_select">
                                 <option value="">— none loaded —</option>
                              </select>
                           </div>
                           <button class="btn btn-outline-secondary btn-sm" type="button" id="btn_lib_clone">
                              <i class="fa fa-book"></i> Clone library template
                           </button>
                           <div id="clone_result" class="mt-3 small"></div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- ============ Step 5 — Mail template (inline editor) ============ -->
               <div class="row step-wrap" id="step5_wrap" style="display:none;">
                  <div class="col-lg-8 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Step 5 — Mail template</h5>
                           <p class="text-muted small">
                              Start from a ranked pretext or blank, then edit inline. <em>Save &amp; wire</em>
                              auto-injects the CTA link to your Step 4 landing URL (with
                              <code>?rid={{RID}}</code>) and the <code>{{TRACKER}}</code> open-pixel,
                              then persists the template.
                           </p>
                           <div class="form-group">
                              <label>Template name</label>
                              <input type="text" class="form-control" id="mt_name" placeholder="Acme M365 reset">
                           </div>
                           <div class="form-group">
                              <label>Subject</label>
                              <input type="text" class="form-control" id="mt_subject" placeholder="Action required: verify your account">
                           </div>
                           <div class="form-group">
                              <label>Body</label>
                              <textarea id="mt_summernote"></textarea>
                           </div>
                           <button class="btn btn-info" type="button" id="btn_mt_save">
                              <i class="fa fa-save"></i> Save &amp; wire
                           </button>
                           <div id="mt_result" class="mt-3"></div>
                           <div id="mt_preview" class="mt-3"></div>
                        </div>
                     </div>
                  </div>
                  <div class="col-lg-4 mb-3">
                     <div class="card h-100">
                        <div class="card-body">
                           <h6 class="card-title">Pretext starters</h6>
                           <p class="small text-muted">Ranked by the OSINT tech-stack signal. Picking one fills the subject + body; or start blank.</p>
                           <button class="btn btn-sm btn-outline-secondary mb-2" type="button" id="btn_mt_blank">
                              <i class="fa fa-file"></i> Start blank
                           </button>
                           <div id="mt_pretexts">—</div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- ============ Step 6 — Sender (select or inline create) ============ -->
               <div class="row step-wrap" id="step6_wrap" style="display:none;">
                  <div class="col-lg-7 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Step 6 — Sender</h5>
                           <p class="text-muted small">
                              Pick an existing SMTP sender, or create one inline. The selected
                              sender's <code>From</code> domain feeds the launch pre-flight.
                           </p>
                           <div class="form-group">
                              <label>Existing senders</label>
                              <select class="form-control" id="snd_select">
                                 <option value="">— loading… —</option>
                              </select>
                           </div>
                           <button class="btn btn-info" type="button" id="btn_snd_use">
                              <i class="fa fa-check"></i> Use selected
                           </button>
                           <button class="btn btn-outline-secondary" type="button" id="btn_snd_toggle">
                              <i class="fa fa-plus"></i> Create new sender
                           </button>
                           <div id="snd_create" class="mt-3" style="display:none;">
                              <div class="form-row">
                                 <div class="form-group col-md-6 mb-2">
                                    <label>Sender name</label>
                                    <input type="text" class="form-control" id="snd_name" placeholder="Acme IT">
                                 </div>
                                 <div class="form-group col-md-6 mb-2">
                                    <label>SMTP server:port</label>
                                    <input type="text" class="form-control" id="snd_smtp" placeholder="smtp.example.com:587">
                                 </div>
                                 <div class="form-group col-md-6 mb-2">
                                    <label>From address</label>
                                    <input type="text" class="form-control" id="snd_from" placeholder="it@acme-corp.example">
                                 </div>
                                 <div class="form-group col-md-6 mb-2">
                                    <label>Username</label>
                                    <input type="text" class="form-control" id="snd_user" placeholder="it@acme-corp.example">
                                 </div>
                                 <div class="form-group col-md-6 mb-2">
                                    <label>Password</label>
                                    <input type="password" class="form-control" id="snd_pwd" autocomplete="new-password">
                                 </div>
                                 <div class="form-group col-md-6 mb-2">
                                    <label>IMAP mailbox (optional)</label>
                                    <input type="text" class="form-control" id="snd_mailbox" placeholder="(blank = auto)">
                                 </div>
                              </div>
                              <button class="btn btn-info" type="button" id="btn_snd_save">
                                 <i class="fa fa-save"></i> Save &amp; select sender
                              </button>
                           </div>
                           <div id="snd_result" class="mt-3"></div>
                        </div>
                     </div>
                  </div>
                  <div class="col-lg-5 mb-3">
                     <div class="card h-100">
                        <div class="card-body">
                           <h6 class="card-title">
                              <a class="text-muted" data-toggle="collapse" href="#snd_dkim_block" role="button">
                                 Advanced — DKIM key pair <i class="fa fa-caret-down"></i>
                              </a>
                           </h6>
                           <div class="collapse" id="snd_dkim_block">
                              <p class="small text-muted">Optional. Generate DKIM/SPF/DMARC TXT records for a look-alike domain.</p>
                              <div class="form-group mb-2">
                                 <label>DKIM selector</label>
                                 <input type="text" id="dkim_selector" class="form-control" value="s1" autocomplete="off">
                              </div>
                              <div class="form-group mb-2">
                                 <label>DMARC <code>rua</code> contact (optional)</label>
                                 <input type="email" id="dkim_rua" class="form-control" placeholder="soc@your-domain.example" autocomplete="off">
                              </div>
                              <button class="btn btn-sm btn-outline-info" type="button" id="btn_gen_dkim">
                                 <i class="fa fa-key"></i> Generate DKIM key pair
                              </button>
                              <div id="dkim_result"></div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- ============ Step 7 — Pre-flight + Launch ============ -->
               <div class="row step-wrap" id="step7_wrap" style="display:none;">
                  <div class="col-12 mb-3">
                     <div class="card">
                        <div class="card-body">
                           <h5 class="card-title">Step 7 — Pre-flight + Launch</h5>
                           <p class="text-muted small">
                              Everything below is auto-filled from Steps 3–6. Run pre-flight; the
                              Launch button stays disabled until every gate is green. Status uses
                              compare-and-swap, so a double-click can't double-launch.
                           </p>
                           <div id="launch_summary" class="mb-3"></div>
                           <div class="form-row align-items-end">
                              <div class="form-group col-md-3 mb-2">
                                 <label>Target DMARC policy</label>
                                 <select id="pf_dmarc" class="form-control">
                                    <option value="none">none</option>
                                    <option value="quarantine">quarantine</option>
                                    <option value="reject">reject</option>
                                 </select>
                              </div>
                              <div class="form-group col-md-3 mb-2">
                                 <label>Target real domain</label>
                                 <input type="text" id="pf_target_domain" class="form-control" placeholder="target.example">
                              </div>
                              <div class="form-group col-md-6 mb-2">
                                 <button class="btn btn-info" type="button" id="btn_run_preflight">
                                    <i class="fa fa-stethoscope"></i> Run pre-flight
                                 </button>
                                 <button class="btn btn-success" type="button" id="btn_launch" disabled>
                                    <i class="fa fa-rocket"></i> Launch
                                 </button>
                              </div>
                           </div>
                           <div id="preflight_result"></div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- Phase 3.56: shared step navigation. The controller shows one
                    step at a time, so this bar always sits at the bottom of the
                    visible wrap. Next is disabled on Step 1 until the engagement
                    is saved, and hidden on Step 7 (Launch is the terminal action). -->
               <div class="row" id="wizard_nav_row">
                  <div class="col-12 mb-4 d-flex align-items-center">
                     <button class="btn btn-outline-secondary" id="wiz_back" type="button" disabled>
                        <i class="fa fa-arrow-left"></i> Back
                     </button>
                     <span class="text-muted small mx-3" id="wiz_step_label">Step 1 of 7</span>
                     <button class="btn btn-info ml-auto" id="wiz_next" type="button" disabled title="Save the engagement first">
                        Next <i class="fa fa-arrow-right"></i>
                     </button>
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
      <script src="js/libs/summernote-bs4.min.js"></script>
      <script src="js/libs/codemirror.min.js"></script>
      <script src="js/common_scripts.js"></script>
      <script src="js/quick_start.js"></script>
      <script src="js/wizard_stepflow.js"></script>
   </body>
</html>
