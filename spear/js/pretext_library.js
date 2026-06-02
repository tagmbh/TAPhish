/* Phase 3.39: pretext library gallery. */

(function () {
    'use strict';

    var PRETEXTS = {}; // {category: [{id, name, subject, body, tags}, ...]}

    function post(payload) {
        return $.ajax({
            url: 'manager/userlist_campaignlist_mailtemplate_manager',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }

    function esc(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    function renderGallery(filter) {
        var $g = $('#pretext_gallery').empty();
        var q = (filter || '').toLowerCase().trim();
        var rendered = 0;

        Object.keys(PRETEXTS).sort().forEach(function (cat) {
            var entries = PRETEXTS[cat].filter(function (p) {
                if (!q) return true;
                var hay = (p.name + ' ' + p.subject + ' ' + (p.tags || '')).toLowerCase();
                return hay.indexOf(q) >= 0;
            });
            if (!entries.length) return;
            var $cat = $('<section class="t-pretext-cat"></section>');
            $cat.append('<h6 class="t-pretext-cat-label">' + esc(cat) + '</h6>');
            var $grid = $('<div class="t-pretext-grid"></div>');
            entries.forEach(function (p) {
                rendered++;
                var $card = $('<article class="t-pretext-card"></article>');
                $card.attr('data-id', p.id);
                $card.append('<h5 class="t-pretext-name">' + esc(p.name) + '</h5>');
                $card.append('<p class="t-pretext-subject">' + esc(p.subject) + '</p>');
                if (p.tags) {
                    var tags = (p.tags || '').split(',').map(function (t) {
                        return '<span class="t-pretext-tag">' + esc(t.trim()) + '</span>';
                    }).join('');
                    $card.append('<div class="t-pretext-tags">' + tags + '</div>');
                }
                var $actions = $('<div class="t-pretext-actions"></div>');
                $actions.append('<button type="button" class="btn btn-sm btn-outline-secondary t-pretext-preview-btn"><i class="fas fa-eye"></i> Preview</button>');
                $actions.append('<button type="button" class="btn btn-sm btn-info t-pretext-clone-btn"><i class="fas fa-copy"></i> Clone</button>');
                $card.append($actions);
                $grid.append($card);
            });
            $cat.append($grid);
            $g.append($cat);
        });

        if (rendered === 0) {
            $g.html('<div class="text-muted">No pretexts match your filter.</div>');
        }
    }

    function findPretext(id) {
        var found = null;
        Object.keys(PRETEXTS).forEach(function (cat) {
            PRETEXTS[cat].forEach(function (p) {
                if (String(p.id) === String(id)) found = p;
            });
        });
        return found;
    }

    function openPreview(id) {
        var p = findPretext(id);
        if (!p) return;
        $('#modal_pretext_title').text(p.name);
        $('#modal_pretext_subject').text(p.subject);
        $('#modal_pretext_body').html(p.body); // intentional: this IS HTML preview
        $('#modal_pretext_clone_btn').data('id', p.id);
        $('#modal_pretext_preview').modal('show');
    }

    function clone(id) {
        post({ action_type: 'clone_pretext_to_my_templates', pretext_id: id })
            .done(function (data) {
                if (data && data.result === 'success') {
                    toastr.success('Pretext cloned. Open Mail Templates to customize.');
                    $('#modal_pretext_preview').modal('hide');
                } else {
                    toastr.error((data && data.error) || 'Clone failed.');
                }
            })
            .fail(function (xhr) {
                toastr.error('Request failed (HTTP ' + xhr.status + ').');
            });
    }

    $(function () {
        post({ action_type: 'list_pretexts' })
            .done(function (data) {
                if (!data || data.result !== 'success') {
                    $('#pretext_gallery').html('<div class="text-danger">Could not load the library.</div>');
                    return;
                }
                PRETEXTS = data.pretexts || {};
                renderGallery('');
            })
            .fail(function (xhr) {
                $('#pretext_gallery').html('<div class="text-danger">Request failed (HTTP ' + xhr.status + ').</div>');
            });

        $('#pretext_search').on('input', function () {
            renderGallery($(this).val());
        });

        $(document).on('click', '.t-pretext-preview-btn', function () {
            var id = $(this).closest('.t-pretext-card').data('id');
            openPreview(id);
        });

        $(document).on('click', '.t-pretext-clone-btn', function () {
            var id = $(this).closest('.t-pretext-card').data('id');
            clone(id);
        });

        $('#modal_pretext_clone_btn').on('click', function () {
            var id = $(this).data('id');
            clone(id);
        });
    });
})();
