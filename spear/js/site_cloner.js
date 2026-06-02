"use strict";

const SITE_CLONER_ENDPOINT = "sniperhost/manager/site_cloner_manager.php";
const WEB_TRACKER_LIST_ENDPOINT = "manager/web_tracker_generator_list_manager.php";

let g_sp_base_url = "";

function postJson(url, payload) {
   return $.ajax({
      url: url,
      method: "POST",
      contentType: "application/json",
      data: JSON.stringify(payload),
      dataType: "json",
   });
}

function postSiteCloner(payload) {
   return postJson(SITE_CLONER_ENDPOINT, payload);
}

function buildPublicUrlFromSlug(slug) {
   return (
      window.location.protocol +
      "//" +
      window.location.host +
      "/spear/sniperhost/cloned/" +
      slug +
      "/"
   );
}

function copyToClipboard(text) {
   if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(
         () => window.toastr && toastr.success("Copied"),
         () => window.toastr && toastr.error("Copy failed")
      );
      return;
   }
   const ta = document.createElement("textarea");
   ta.value = text;
   ta.setAttribute("readonly", "");
   ta.style.position = "absolute";
   ta.style.left = "-9999px";
   document.body.appendChild(ta);
   ta.select();
   try {
      document.execCommand("copy");
      window.toastr && toastr.success("Copied");
   } catch (_) {
      window.toastr && toastr.error("Copy failed");
   }
   document.body.removeChild(ta);
}

function renderCloneResult(res) {
   const target = $("#clone_result");
   target.empty();
   if (res.result !== "success") {
      target.append(
         $("<div>").addClass("alert alert-danger").text(res.error || "Clone failed")
      );
      return;
   }
   const publicUrl = res.public_url || buildPublicUrlFromSlug(res.slug);
   const box = $("<div>").addClass("alert alert-success");
   box.append(
      $("<strong>").text("Cloned: "),
      $("<code>").text(res.slug),
      $("<br>"),
      $("<small>").text(
         "Assets: " + res.asset_count + " · HTML bytes: " + res.bytes + " · On disk: " + res.path
      )
   );

   const urlGroup = $("<div>").addClass("input-group input-group-sm mt-3");
   urlGroup.append(
      $("<div>").addClass("input-group-prepend").append(
         $("<span>").addClass("input-group-text").text("Public URL")
      )
   );
   const urlInput = $("<input>")
      .attr("type", "text")
      .attr("readonly", true)
      .addClass("form-control")
      .val(publicUrl)
      .on("focus", function () { this.select(); });
   urlGroup.append(urlInput);
   urlGroup.append(
      $("<div>").addClass("input-group-append").append(
         $("<button>")
            .addClass("btn btn-outline-secondary")
            .attr("type", "button")
            .text("Copy")
            .on("click", () => copyToClipboard(publicUrl)),
         $("<a>")
            .addClass("btn btn-outline-secondary")
            .attr("href", publicUrl)
            .attr("target", "_blank")
            .attr("rel", "noopener noreferrer")
            .text("Open")
      )
   );
   box.append(urlGroup);

   const help = $("<div>").addClass("mt-3 small");
   help.append(
      $("<strong>").text("Use this clone in a campaign:"),
      $("<ol>").addClass("mb-0 pl-3").append(
         $("<li>").html(
            'In <a href="MailCampaign">Mail Campaign</a> open or create a campaign, then set the landing/target URL to the <em>Public URL</em> above.'
         ),
         $("<li>").html(
            'Or in <a href="WebTracker">Web Tracker</a> create a tracker that points at the Public URL — the tracker script is already injected into the clone if you picked one above.'
         ),
         $("<li>").text(
            "Optional: re-clone with Force-overwrite checked once you wire the tracker, so the same slug stays live."
         )
      )
   );
   box.append(help);

   if (res.warnings && res.warnings.length) {
      const list = $("<ul>").addClass("mt-3 mb-0");
      res.warnings.forEach((w) => list.append($("<li>").text(w)));
      box.append($("<div>").addClass("mt-3 small text-warning").text("Warnings:"));
      box.append(list);
   }
   target.append(box);
}

function renderCloneList(clones) {
   const tbody = $("#tb_clones tbody");
   tbody.empty();
   if (!clones.length) {
      tbody.append(
         $("<tr>").append($("<td colspan=5>").addClass("text-muted").text("No clones yet."))
      );
      return;
   }
   clones.forEach((c) => {
      const meta = c.meta || {};
      const publicUrl = c.public_url || buildPublicUrlFromSlug(c.slug);
      const tr = $("<tr>");
      tr.append(
         $("<td>").append(
            $("<code>").text(c.slug),
            $("<br>"),
            $("<a>")
               .attr("href", publicUrl)
               .attr("target", "_blank")
               .attr("rel", "noopener noreferrer")
               .addClass("small")
               .text("open"),
            $("<span>").addClass("small text-muted").text(" · "),
            $("<a>")
               .attr("href", "#")
               .addClass("small")
               .text("copy URL")
               .on("click", (e) => {
                  e.preventDefault();
                  copyToClipboard(publicUrl);
               })
         )
      );
      tr.append(
         $("<td>").append(
            $("<a>")
               .attr("href", meta.source_url || "#")
               .attr("target", "_blank")
               .attr("rel", "noopener noreferrer")
               .text(meta.source_url || "—")
         )
      );
      tr.append($("<td>").text(meta.asset_count != null ? meta.asset_count : "—"));
      tr.append($("<td>").text(meta.created_at || "—"));
      const del = $("<button>")
         .addClass("btn btn-sm btn-outline-danger")
         .attr("type", "button")
         .text("Delete")
         .on("click", () => deleteClone(c.slug));
      tr.append($("<td>").append(del));
      tbody.append(tr);
   });
}

function refreshList() {
   postSiteCloner({ action_type: "list_clones" })
      .done((res) => {
         if (res.result === "success") {
            renderCloneList(res.clones || []);
         }
      })
      .fail(() => {
         renderCloneList([]);
      });
}

function deleteClone(slug) {
   if (!confirm("Delete clone '" + slug + "'? This removes its directory on disk.")) return;
   postSiteCloner({ action_type: "delete_clone", slug: slug })
      .done((res) => {
         if (res.result === "success") {
            refreshList();
         } else {
            alert(res.error || "Delete failed");
         }
      });
}

function buildTrackerScriptUrl(trackerId) {
   if (!trackerId) return "";
   const base = g_sp_base_url || window.location.origin;
   return base.replace(/\/$/, "") + "/mod?tlink=" + encodeURIComponent(trackerId);
}

function loadSpBaseUrl() {
   return postJson(WEB_TRACKER_LIST_ENDPOINT, { action_type: "get_SP_base_URL" })
      .done((data) => {
         if (data && data.baseurl) {
            g_sp_base_url = data.baseurl;
         }
      });
}

function loadTrackerOptions() {
   return postJson(WEB_TRACKER_LIST_ENDPOINT, { action_type: "get_web_tracker_list_for_modal" })
      .done((data) => {
         const sel = $("#sel_tracker");
         sel.find("option:not([value=''])").remove();
         if (!Array.isArray(data)) return; // {error: 'No data'} response
         data.forEach((t) => {
            sel.append(
               $("<option>")
                  .val(t.tracker_id)
                  .text(t.tracker_name + " (" + t.tracker_id + ")")
            );
         });
      });
}

function wireTrackerSelector() {
   $("#sel_tracker").on("change", function () {
      const trackerId = $(this).val();
      $("#in_tracker").val(buildTrackerScriptUrl(trackerId));
   });
   // If the operator types a custom URL, clear the dropdown to avoid implying
   // the two are in sync.
   $("#in_tracker").on("input", function () {
      const sel = $("#sel_tracker");
      if (sel.val() && $(this).val() !== buildTrackerScriptUrl(sel.val())) {
         sel.val("");
      }
   });
}

$(function () {
   wireTrackerSelector();
   loadSpBaseUrl().always(loadTrackerOptions);

   $("#frm_clone").on("submit", function (e) {
      e.preventDefault();
      const btn = $("#btn_clone");
      btn.prop("disabled", true);
      $("#clone_result")
         .empty()
         .append($("<div>").addClass("text-muted").text("Cloning…"));
      const payload = {
         action_type: "clone_site",
         url: $("#in_url").val(),
         slug: $("#in_slug").val(),
         tracker_url: $("#in_tracker").val() || null,
         download_css: $("#cb_download_css").is(":checked"),
         download_images: $("#cb_download_images").is(":checked"),
         allow_private: $("#cb_allow_private").is(":checked"),
         force: $("#cb_force").is(":checked"),
         beef_hook_enabled: $("#cb_beef_hook").is(":checked"),
      };
      postSiteCloner(payload)
         .done((res) => {
            renderCloneResult(res);
            // Phase 3.52 task 5: surface the BeEF-not-configured warning.
            if (res && res.beef_warning && window.toastr) {
               toastr.warning(res.beef_warning, "BeEF hook not injected");
            }
            refreshList();
         })
         .fail((xhr) => {
            renderCloneResult({
               result: "failed",
               error: "Request failed (HTTP " + xhr.status + ")",
            });
         })
         .always(() => btn.prop("disabled", false));
   });

   $("#btn_refresh").on("click", refreshList);
   refreshList();
});
