/** Canonical server-rendered bulk operations. */
/* global jQuery */
(function ($) {
    'use strict';
    var app = window.cpicApp;
    if (!app) return;
    var busy = false;

    function buttons(disabled) {
        $('#cpic-bulk-download, #cpic-bulk-fb, #cpic-bulk-tg').prop('disabled', disabled);
    }
    function runSequential(action, label) {
        var ids = app.getSelectedPostIds();
        if (!ids.length) { app.logStatus('Please select at least one post.', 'error'); return; }
        if (busy) return;
        busy = true; buttons(true);
        var total = ids.length, index = 0;
        function next() {
            if (index >= total) { busy = false; buttons(false); app.loadPosts(); return; }
            var id = ids[index++];
            app.logStatus(label + ' ' + index + ' of ' + total + ' (post ' + id + ')…', 'info');
            app.ajax(action, { post_id: id }, function (data) {
                app.logStatus('Post ' + id + ': ' + (data.message || 'Complete.'), 'success');
                window.setTimeout(next, 500);
            }, function (message) {
                app.logStatus('Post ' + id + ': ' + message, 'error');
                window.setTimeout(next, 500);
            });
        }
        next();
    }
    function bulkDownload() {
        var ids = app.getSelectedPostIds();
        if (!ids.length) { app.logStatus('Please select at least one post.', 'error'); return; }
        if (busy) return;
        busy = true; buttons(true);
        app.logStatus('Generating canonical images and ZIP…', 'info');
        app.ajax('cpic_bulk_download', { post_ids: ids }, function (data) {
            busy = false; buttons(false);
            var link = document.createElement('a'); link.href = data.url; link.download = 'cpic-images.zip';
            document.body.appendChild(link); link.click(); link.remove();
            app.logStatus('ZIP ready: ' + data.generated + ' image(s).', 'success');
            (data.failures || []).forEach(function (failure) {
                app.logStatus('Post ' + failure.post_id + ': ' + failure.message, 'error');
            });
        }, function (message) { busy = false; buttons(false); app.logStatus(message, 'error'); });
    }
    $(function () {
        $('#cpic-bulk-download').on('click', bulkDownload);
        $('#cpic-bulk-fb').on('click', function () { runSequential('cpic_fb_share', 'Publishing to Facebook'); });
        $('#cpic-bulk-tg').on('click', function () { runSequential('cpic_tg_share', 'Publishing to Telegram'); });
    });
})(jQuery);
