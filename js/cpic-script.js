/**
 * Custom Post Image Creator v6.5.3 – Main JS
 *
 * Handles:
 *   - Post loading, filtering, sorting, pagination
 *   - Canonical server-side post previews and downloads
 *   - Settings save / load
 *   - Single share to Facebook & Telegram
 *   - Dark mode toggle
 *   - Facebook page connection flow
 *   - Telegram test
 */

/* global jQuery, cpicData */
(function ($) {
    'use strict';

    /* ── Shortcuts ──────────────────────────────── */
    var ajax  = cpicData.ajaxUrl;
    var nonce = cpicData.nonce;
    var nf    = cpicData.nonceField;
    var i18n  = cpicData.i18n;

    /* ── State ──────────────────────────────────── */
    var posts       = [];
    var currentPage = 1;
    var sortField   = 'date';
    var sortDir     = 'desc';
    var selectedPost = null; // full post object currently previewed

    /* ============================================================
       AJAX helper
       ============================================================ */
    function cpicAjax(action, data, onSuccess, onError) {
        data = data || {};
        data.action = action;
        data[nf]    = nonce;
        $.post(ajax, data, function (res) {
            if (res.success) {
                if (onSuccess) onSuccess(res.data);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : i18n.error;
                if (onError) onError(msg);
                else alert(msg);
            }
        }).fail(function () {
            if (onError) onError(i18n.error);
            else alert(i18n.error);
        });
    }

    /* ============================================================
       Dark Mode
       ============================================================ */
    function initDarkMode() {
        if (localStorage.getItem('cpic_dark') === '1') {
            $('body').addClass('cpic-dark');
        }
        $('#cpic-dark-toggle').on('click', function () {
            $('body').toggleClass('cpic-dark');
            localStorage.setItem('cpic_dark', $('body').hasClass('cpic-dark') ? '1' : '0');
        });
    }

    /* ============================================================
       Posts – Load / Filter / Sort / Paginate
       ============================================================ */
    function loadPosts(page, dateFrom, dateTo) {
        page = page || 1;
        currentPage = page;

        var data = { paged: page };
        if (dateFrom) data.date_from = dateFrom;
        if (dateTo)   data.date_to   = dateTo;

        cpicAjax('cpic_get_posts', data, function (d) {
            posts = d.posts;
            renderPosts();
            renderPagination(d.total_pages, d.page);
        });
    }

    function renderPosts() {
        var $body = $('#cpic-posts-body');
        $body.empty();

        if (!posts.length) {
            $body.append('<tr><td colspan="7">No posts found.</td></tr>');
            return;
        }

        // Client-side sort
        var sorted = posts.slice().sort(function (a, b) {
            var va = a[sortField] || '';
            var vb = b[sortField] || '';
            if (sortDir === 'asc') return va > vb ? 1 : -1;
            return va < vb ? 1 : -1;
        });

        $.each(sorted, function (_, p) {
            var img = p.featured_image
                ? '<img class="cpic-thumb" src="' + escHtml(p.featured_image) + '" alt="" />'
                : '—';
            $body.append(
                '<tr data-id="' + p.id + '">' +
                '<td class="cpic-check-col"><input type="checkbox" class="cpic-post-check" value="' + p.id + '" /></td>' +
                '<td class="cpic-post-title">' + escHtml(p.title) + '</td>' +
                '<td>' + escHtml(p.author) + '</td>' +
                '<td>' + escHtml(p.bangla_date) + '</td>' +
                '<td>' + img + '</td>' +
                '<td>' + (p.last_fb || '—') + '</td>' +
                '<td>' + (p.last_tg || '—') + '</td>' +
                '</tr>'
            );
        });

        // Click row title to select for preview
        $body.find('.cpic-post-title').on('click', function () {
            var id = $(this).closest('tr').data('id');
            selectedPost = posts.find(function (p) { return p.id === id; });
            $(this).closest('tr').addClass('cpic-row-selected').siblings().removeClass('cpic-row-selected');
            previewPostServer(id);
        });
    }

    function renderPagination(totalPages, current) {
        var $pag = $('#cpic-pagination');
        $pag.empty();
        if (totalPages <= 1) return;
        for (var i = 1; i <= totalPages; i++) {
            var cls = i === current ? 'button active' : 'button';
            $pag.append('<button class="' + cls + '" data-page="' + i + '">' + i + '</button>');
        }
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    var serverPreviewUrl = '';

    /** Render and persist the exact browser PNG later reused by shares and jobs. */
    function previewPostServer(postId) {
        postId = parseInt(postId, 10) || 0;
        if (!postId || !window.cpicRenderPost) { return; }
        var post = posts.find(function (p) { return p.id === postId; });
        if (post) { selectedPost = post; }
        var $img = $('#cpic-server-preview');
        var $info = $('#cpic-preview-info');
        serverPreviewUrl = '';
        $img.hide();
        $info.show().text(i18n.processing || 'Rendering Bengali text in your browser…');
        window.cpicRenderPost(postId).then(function (result) {
            serverPreviewUrl = result.url;
            $img.attr('src', result.url + '?t=' + Date.now()).show();
            $info.text((result.template_name || 'Template') + ' — ' + result.renderer + ' — canonical PNG saved').show();
        }).catch(function (error) {
            $info.text('Template preview failed: ' + error.message).show();
            logStatus('Template preview failed: ' + error.message, 'error');
        });
    }

    /* ============================================================
       Settings – Save / Load
       ============================================================ */
    function collectSettings() {
        var data = {};
        var fields = [
            'cpic_comment_message',
            'cpic_fb_app_id',
            'cpic_tg_chat_id',
            'cpic_auto_delay',
            'cpic_max_retries',
            'cpic_default_template_id'
        ];
        $.each(fields, function (_, f) {
            data[f] = $('#' + f).val();
        });

        // Secret/token fields: only send when the user typed a new value,
        // so an empty (placeholder) field never wipes the saved secret.
        var secret = $.trim($('#cpic_fb_app_secret').val());
        if (secret) {
            data.cpic_fb_app_secret = secret;
        }
        var token = $.trim($('#cpic_tg_bot_token').val());
        if (token) {
            data.cpic_tg_bot_token = token;
        }

        // Checkboxes
        data.cpic_auto_fb      = $('#cpic_auto_fb').is(':checked') ? 1 : 0;
        data.cpic_auto_tg      = $('#cpic_auto_tg').is(':checked') ? 1 : 0;
        data.cpic_auto_generate = $('#cpic_auto_generate').is(':checked') ? 1 : 0;
        data.cpic_auto_comment  = $('#cpic_auto_comment').is(':checked') ? 1 : 0;
        data.cpic_delete_data_on_uninstall = $('#cpic_delete_data_on_uninstall').is(':checked') ? 1 : 0;
        return data;
    }

    function saveSettings(onDone) {
        var data = collectSettings();
        cpicAjax('cpic_save_settings', data, function () {
            $('#cpic-save-status').text(i18n.saved).fadeIn().delay(2000).fadeOut();
            if (onDone) onDone(data);
        });
    }

    /* ============================================================
       Single Download
       ============================================================ */
    function downloadPreview() {
        if (!serverPreviewUrl) {
            logStatus('Select a post and wait for its browser-rendered preview before downloading.', 'error');
            return;
        }
        var a = document.createElement('a');
        a.href = serverPreviewUrl;
        a.download = 'cpic-image-' + (selectedPost ? selectedPost.id : '') + '.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    /* ============================================================
       Single Share helpers
       ============================================================ */
    function getSelectedPostIds() {
        var ids = [];
        $('.cpic-post-check:checked').each(function () {
            ids.push(parseInt($(this).val(), 10));
        });
        return ids;
    }

    function sendServerShareRequest(action, postId, onSuccess, onError) {
        if (!window.cpicRenderPost) { if (onError) onError('Browser Canvas renderer is unavailable.'); return; }
        window.cpicRenderPost(postId).then(function () {
            cpicAjax(action, { post_id: postId }, onSuccess, onError);
        }).catch(function (error) { if (onError) onError(error.message); });
    }

    function shareToFacebook(postId) {
        sendServerShareRequest('cpic_fb_share', postId, function (d) {
            logStatus(d.message, 'success');
            loadPosts(currentPage);
        }, function (msg) { logStatus(msg, 'error'); });
    }

    function shareToTelegram(postId) {
        sendServerShareRequest('cpic_tg_share', postId, function (d) {
            logStatus(d.message, 'success');
            loadPosts(currentPage);
        }, function (msg) { logStatus(msg, 'error'); });
    }

    /* ============================================================
       Status Log
       ============================================================ */
    function logStatus(msg, type) {
        var $log = $('#cpic-status-log');
        $log.addClass('visible');
        var cls = type === 'success' ? 'cpic-log-success' : 'cpic-log-error';
        $log.prepend('<div class="' + cls + '">[' + new Date().toLocaleTimeString() + '] ' + escHtml(msg) + '</div>');
    }

    /* ============================================================
       Queue Monitor
       ============================================================ */
    function renderQueueMonitor(data) {
        data = data || {};

        $('#cpic-queue-pending').text(data.pending_count || 0);
        $('#cpic-queue-due').text(data.due_count || 0);
        $('#cpic-queue-next-cron').text(data.next_cron_human || '-');

        var $body = $('#cpic-queue-body');
        var items = data.items || [];
        $body.empty();

        if (!items.length) {
            $body.append('<tr><td colspan="5">Queue is empty.</td></tr>');
        } else {
            $.each(items, function (_, item) {
                var targets = [];
                if (parseInt(item.auto_fb, 10) === 1) targets.push('Facebook');
                if (parseInt(item.auto_tg, 10) === 1) targets.push('Telegram');
                if (!targets.length) targets.push('None');

                $body.append(
                    '<tr>' +
                    '<td>' + escHtml(String(item.post_id || '')) + '</td>' +
                    '<td>' + escHtml(item.post_title || '') + '</td>' +
                    '<td>' + escHtml(item.scheduled_at_human || '-') + '</td>' +
                    '<td>' + escHtml(targets.join(', ')) + '</td>' +
                    '<td>' + escHtml(item.status || '') + '</td>' +
                    '</tr>'
                );
            });
        }

        var $logs = $('#cpic-queue-logs');
        var logs = data.logs || [];
        $logs.empty();

        if (!logs.length) {
            $logs.append('<div>No recent logs.</div>');
            return;
        }

        $.each(logs, function (_, row) {
            var type = row.log_type || 'general';
            var cls = (type === 'facebook' || type === 'telegram' || type === 'imagick') ? 'cpic-log-error' : 'cpic-log-success';
            $logs.append(
                '<div class="' + cls + '">[' + escHtml(row.created_at || '') + '] ' +
                '<strong>' + escHtml(type) + '</strong>: ' + escHtml(row.message || '') + '</div>'
            );
        });
    }

    function loadQueueMonitor() {
        if (!$('#cpic-queue-body').length) {
            return;
        }

        cpicAjax('cpic_queue_status', {}, function (d) {
            renderQueueMonitor(d);
        }, function (msg) {
            logStatus('Queue monitor error: ' + msg, 'error');
        });
    }

    function initQueueMonitor() {
        if (!$('#cpic-queue-body').length) {
            return;
        }

        $('#cpic-queue-refresh').on('click', function () {
            loadQueueMonitor();
        });

        $('#cpic-queue-process-now').on('click', function () {
            cpicAjax('cpic_queue_process_now', {}, function (d) {
                var monitor = d.monitor || {};
                renderQueueMonitor(monitor);
                logStatus(d.message || 'Queue processed.', 'success');
            }, function (msg) {
                logStatus(msg, 'error');
            });
        });

        loadQueueMonitor();
        window.setInterval(loadQueueMonitor, 30000);
    }

    /* ============================================================
       Facebook connection flow (OAuth popup)
       ============================================================ */
    function initFacebook() {
        // Save App ID and App Secret.
        $('#cpic-fb-save-creds').on('click', function () {
            var data = {};
            var appId = $.trim($('#cpic_fb_app_id').val());
            var appSecret = $('#cpic_fb_app_secret').val();
            if (appId) data.cpic_fb_app_id = appId;
            if (appSecret) data.cpic_fb_app_secret = appSecret;
            if (!appId) { alert('App ID is required.'); return; }
            cpicAjax('cpic_save_settings', data, function () {
                logStatus('Facebook credentials saved.', 'success');
            });
        });

        // Open OAuth popup — direct to admin-post.php (no AJAX roundtrip).
        $('#cpic-fb-oauth-btn').on('click', function () {
            var appId = $.trim($('#cpic_fb_app_id').val());
            if (!appId) { alert('Please enter your Facebook App ID first.'); return; }

            // Save credentials first, then open popup.
            var credData = {};
            credData.cpic_fb_app_id = appId;
            var secret = $('#cpic_fb_app_secret').val();
            if (secret) credData.cpic_fb_app_secret = secret;

            cpicAjax('cpic_save_settings', credData, function () {
                // Popup goes directly to admin-post.php which renders a JS-redirect page.
                var url = ajax.replace('admin-ajax.php', 'admin-post.php') + '?action=cpic_fb_oauth_start';
                var popup = window.open(url, 'cpic_fb_oauth', 'width=700,height=700,scrollbars=yes,resizable=yes');
                if (!popup) { alert('Popup blocked. Please allow popups for this site.'); return; }
                var pollInterval = setInterval(function () {
                    if (!popup || popup.closed) {
                        clearInterval(pollInterval);
                        checkFacebookStatus();
                    }
                }, 1000);
            });
        });

        // Listen for postMessage from OAuth popup.
        window.addEventListener('message', function (e) {
            if (e.data && e.data.cpic_fb_connected !== undefined) {
                checkFacebookStatus();
            }
        });

        // Reconnect button.
        $(document).on('click', '#cpic-fb-reconnect', function () {
            $('#cpic-fb-oauth-btn').trigger('click');
        });

        // Disconnect button.
        $(document).on('click', '#cpic-fb-disconnect', function () {
            cpicAjax('cpic_fb_disconnect', {}, function (d) {
                $('#cpic-fb-status').html('<p class="cpic-disconnected">Not connected</p>');
                logStatus(d.message, 'success');
            });
        });
    }

    /**
     * Poll server for Facebook connection status and update UI.
     */
    function checkFacebookStatus() {
        cpicAjax('cpic_fb_oauth_status', {}, function (d) {
            if (d.connected) {
                $('#cpic-fb-status').html(
                    '<p class="cpic-connected">Connected: ' + escHtml(d.page_name) + '</p>' +
                    '<button type="button" id="cpic-fb-reconnect" class="button">Reconnect</button> ' +
                    '<button type="button" id="cpic-fb-disconnect" class="button button-secondary">Disconnect</button>'
                );
                logStatus('Facebook Page connected: ' + d.page_name, 'success');
            } else {
                $('#cpic-fb-status').html('<p class="cpic-disconnected">Not connected</p>');
            }
        });
    }

    /* ============================================================
       Telegram test
       ============================================================ */
    function initTelegram() {
        $('#cpic-tg-test').on('click', function () {
            // Save tokens first (only if a new value was entered).
            var data = {
                cpic_tg_chat_id: $('#cpic_tg_chat_id').val()
            };
            var token = $.trim($('#cpic_tg_bot_token').val());
            if (token) {
                data.cpic_tg_bot_token = token;
            }
            cpicAjax('cpic_save_settings', data, function () {
                cpicAjax('cpic_tg_test', {}, function (d) {
                    $('#cpic-tg-status').text('✓ ' + d.bot.first_name).css('color', 'green');
                }, function (msg) {
                    $('#cpic-tg-status').text('✗ ' + msg).css('color', 'red');
                });
            });
        });
    }

    /* ============================================================
       Init
       ============================================================ */
    $(document).ready(function () {
        initDarkMode();

        // Load posts
        loadPosts(1);

        // Filter
        $('#cpic-filter-posts').on('click', function () {
            loadPosts(1, $('#cpic-date-from').val(), $('#cpic-date-to').val());
        });
        $('#cpic-load-all').on('click', function () {
            $('#cpic-date-from').val('');
            $('#cpic-date-to').val('');
            loadPosts(1, '2000-01-01', '2099-12-31');
        });

        // Pagination
        $(document).on('click', '#cpic-pagination button', function () {
            loadPosts($(this).data('page'), $('#cpic-date-from').val(), $('#cpic-date-to').val());
        });

        // Select all
        $('#cpic-select-all').on('change', function () {
            var checked = $(this).is(':checked');
            $('.cpic-post-check').prop('checked', checked);
        });

        // Sort
        $(document).on('click', '.cpic-sortable', function () {
            var field = $(this).data('sort');
            if (sortField === field) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortField = field;
                sortDir = 'asc';
            }
            renderPosts();
        });

        // Preview button
        $('#cpic-preview-btn').on('click', function () {
            var ids = getSelectedPostIds();
            if (ids.length === 0 && !selectedPost) {
                alert(i18n.noSelection);
                return;
            }
            if (ids.length > 0) {
                selectedPost = posts.find(function (p) { return p.id === ids[0]; });
            }
            previewPostServer(selectedPost.id);
        });

        // Download
        $('#cpic-download-btn').on('click', downloadPreview);

        // Single FB share
        $('#cpic-fb-share-btn').on('click', function () {
            var ids = getSelectedPostIds();
            if (!ids.length) { alert(i18n.noSelection); return; }
            shareToFacebook(ids[0]);
        });

        // Single TG share
        $('#cpic-tg-share-btn').on('click', function () {
            var ids = getSelectedPostIds();
            if (!ids.length) { alert(i18n.noSelection); return; }
            shareToTelegram(ids[0]);
        });

        // Save settings
        $('#cpic-save-settings').on('click', saveSettings);

        initFacebook();
        initTelegram();
        initQueueMonitor();
    });

    /* Expose for bulk-processing.js */
    window.cpicApp = {
        getSelectedPostIds: getSelectedPostIds,
        posts:              function () { return posts; },
        logStatus:          logStatus,
        loadPosts:          function () { loadPosts(currentPage); },
        ajax:               cpicAjax,
        renderPost:         function (postId) { return window.cpicRenderPost(postId); },
        escHtml:            escHtml
    };

})(jQuery);
