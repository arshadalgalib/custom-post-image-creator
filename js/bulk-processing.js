/** Bulk actions render each canonical PNG once in the browser before server-side delivery. */
/* global jQuery */
(function ($) {
    'use strict';
    var app = window.cpicApp;
    if (!app) return;
    var busy = false;

    function buttons(disabled) { $('#cpic-bulk-download, #cpic-bulk-fb, #cpic-bulk-tg').prop('disabled', disabled); }
    function renderAll(ids, label, done) {
        var index = 0, ready = [], failures = [];
        function next() {
            if (index >= ids.length) { done(ready, failures); return; }
            var id = ids[index++];
            app.logStatus(label + ' ' + index + ' of ' + ids.length + ' (post ' + id + ')…', 'info');
            app.renderPost(id).then(function () {
                ready.push(id);
            }).catch(function (error) {
                failures.push({ post_id: id, message: error.message });
                app.logStatus('Post ' + id + ': ' + error.message, 'error');
            }).then(next);
        }
        next();
    }
    function runSequential(action, label) {
        var ids = app.getSelectedPostIds();
        if (!ids.length) { app.logStatus('Please select at least one post.', 'error'); return; }
        if (busy) return;
        busy = true; buttons(true);
        renderAll(ids, 'Rendering ' + label, function (ready, failures) {
            var index = 0;
            function next() {
                if (index >= ready.length) {
                    busy = false; buttons(false); app.loadPosts();
                    if (!ready.length && failures.length) app.logStatus('No posts were rendered; nothing was published.', 'error');
                    return;
                }
                var id = ready[index++];
                app.logStatus(label + ' ' + index + ' of ' + ready.length + ' (post ' + id + ')…', 'info');
                app.ajax(action, { post_id: id }, function (data) {
                    app.logStatus('Post ' + id + ': ' + (data.message || 'Complete.'), 'success');
                    window.setTimeout(next, 500);
                }, function (message) {
                    app.logStatus('Post ' + id + ': ' + message, 'error');
                    window.setTimeout(next, 500);
                });
            }
            next();
        });
    }
    function bulkDownload() {
        var ids = app.getSelectedPostIds();
        if (!ids.length) { app.logStatus('Please select at least one post.', 'error'); return; }
        if (busy) return;
        busy = true; buttons(true);
        renderAll(ids, 'Rendering bulk card', function (ready, failures) {
            if (!ready.length) { busy = false; buttons(false); app.logStatus('No canonical PNGs were rendered.', 'error'); return; }
            app.logStatus('Building ZIP from the exact browser-rendered PNGs…', 'info');
            app.ajax('cpic_bulk_download', { post_ids: ready }, function (data) {
                busy = false; buttons(false);
                var link = document.createElement('a'); link.href = data.url; link.download = 'cpic-images.zip';
                document.body.appendChild(link); link.click(); link.remove();
                app.logStatus('ZIP ready: ' + data.generated + ' image(s).', 'success');
                failures.forEach(function (failure) { app.logStatus('Post ' + failure.post_id + ': ' + failure.message, 'error'); });
                (data.failures || []).forEach(function (failure) { app.logStatus('Post ' + failure.post_id + ': ' + failure.message, 'error'); });
            }, function (message) { busy = false; buttons(false); app.logStatus(message, 'error'); });
        });
    }
    $(function () {
        $('#cpic-bulk-download').on('click', bulkDownload);
        $('#cpic-bulk-fb').on('click', function () { runSequential('cpic_fb_share', 'Publishing to Facebook'); });
        $('#cpic-bulk-tg').on('click', function () { runSequential('cpic_tg_share', 'Publishing to Telegram'); });
    });
})(jQuery);
