// Phase 3.46: landing-page library gallery + clone-to-my-sites flow.
(function ($) {
    'use strict';

    function post(payload) {
        return $.ajax({
            url: 'manager/userlist_campaignlist_mailtemplate_manager',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    function patternBadge(pattern) {
        var palette = {
            'multi-step':        'badge-primary',
            'single-page':       'badge-info',
            'redirect-then-form':'badge-warning'
        };
        return '<span class="badge ' + (palette[pattern] || 'badge-secondary') + '">' + esc(pattern) + '</span>';
    }

    function renderCard(t) {
        var twoFa = t.has_2fa
            ? '<span class="badge badge-success ml-1">+2FA capture</span>'
            : '';
        var fields = (t.fields || []).map(function (f) {
            return '<code class="small">' + esc(f) + '</code>';
        }).join(' &middot; ');
        var notes = t.placeholder_notes
            ? '<div class="alert alert-light small mt-2 mb-2">' +
              '<strong>Customize before launch:</strong> ' + esc(t.placeholder_notes) +
              '</div>'
            : '';
        return '' +
          '<div class="col-md-6 col-lg-4 mb-3">' +
            '<div class="card h-100">' +
              '<div class="card-body d-flex flex-column">' +
                '<h6 class="card-title mb-1">' + esc(t.name) + '</h6>' +
                '<div class="mb-2">' + patternBadge(t.pattern) + twoFa + '</div>' +
                '<p class="card-text small text-muted">' + esc(t.description) + '</p>' +
                '<div class="small mb-1">Captures: ' + (fields || '<em class="text-muted">none declared</em>') + '</div>' +
                notes +
                '<div class="mt-auto pt-2">' +
                  '<button type="button" class="btn btn-info btn-sm btn-clone" data-slug="' + esc(t.slug) + '" data-name="' + esc(t.name) + '">' +
                    '<i class="fa fa-clone"></i> Clone to my sites' +
                  '</button>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</div>';
    }

    function loadGrid() {
        var $grid = $('#lib_grid');
        $grid.html('<div class="col-12 text-muted small">Loading…</div>');
        post({ action_type: 'library_list' })
            .done(function (d) {
                if (!d || d.result !== 'success') {
                    $grid.html('<div class="col-12 text-danger">Failed to load library.</div>');
                    return;
                }
                var lib = d.library || [];
                if (!lib.length) {
                    $grid.html(
                        '<div class="col-12 text-muted small">' +
                        'No templates found under <code>spear/sniperhost/library/</code>.' +
                        '</div>'
                    );
                    return;
                }
                $grid.html(lib.map(renderCard).join(''));
            })
            .fail(function () {
                $grid.html('<div class="col-12 text-danger">Request failed.</div>');
            });
    }

    function suggestDestSlug(sourceSlug) {
        // Lightweight default: source slug + 4 random alphanumeric chars.
        var rand = Math.random().toString(36).slice(2, 6);
        return sourceSlug + '-' + rand;
    }

    function openCloneModal(sourceSlug, sourceName) {
        $('#source_slug').val(sourceSlug);
        $('#modal_template_name').text(sourceName);
        $('#dest_slug').val(suggestDestSlug(sourceSlug));
        $('#tracker_url').val('');
        $('#post_url').val('');
        $('#cb_force').prop('checked', false);
        $('#clone_result').empty();
        $('#modal_clone').modal('show');
    }

    function runClone() {
        var $btn = $('#btn_do_clone');
        $btn.prop('disabled', true);
        var payload = {
            action_type: 'library_clone_to_my_sites',
            source_slug: $('#source_slug').val(),
            dest_slug:   $('#dest_slug').val().trim(),
            tracker_url: $('#tracker_url').val().trim(),
            post_url:    $('#post_url').val().trim(),
            force:       $('#cb_force').is(':checked')
        };
        post(payload)
            .done(function (d) {
                if (d && d.result === 'success') {
                    var openUrl = location.origin + '/' + (d.path || '');
                    $('#clone_result').html(
                        '<div class="alert alert-success small">' +
                        '<strong>Cloned.</strong> ' + d.files + ' file(s) written to <code>' + esc(d.path) + '</code>. ' +
                        '<a href="' + openUrl + '" target="_blank" rel="noopener noreferrer">Open the landing page</a>' +
                        ' &middot; <a href="SiteCloner">Manage clones</a>' +
                        '</div>'
                    );
                    if (window.toastr) toastr.success('Library entry cloned', d.slug);
                } else {
                    $('#clone_result').html(
                        '<div class="alert alert-warning small">' +
                        esc((d && d.error) || 'Clone failed') +
                        '</div>'
                    );
                }
            })
            .fail(function () {
                $('#clone_result').html('<div class="alert alert-danger small">Request failed.</div>');
            })
            .always(function () { $btn.prop('disabled', false); });
    }

    $(function () {
        loadGrid();
        $('#lib_grid').on('click', '.btn-clone', function () {
            openCloneModal($(this).data('slug'), $(this).data('name'));
        });
        $('#btn_do_clone').on('click', runClone);
    });
})(jQuery);
