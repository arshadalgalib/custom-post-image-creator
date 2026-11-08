/**
 * Custom Post Image Creator v5.2 – Main JS
 *
 * Handles:
 *   - Post loading, filtering, sorting, pagination
 *   - Canvas preview rendering (Background, Featured Image, Title, Date, Logo)
 *   - Settings save / load
 *   - Single share to Facebook & Telegram
 *   - Single image download
 *   - Dark mode toggle
 *   - WordPress Media Uploader for logo & backgrounds
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
    var S     = cpicData.settings;
    var i18n  = cpicData.i18n;

    /* ── State ──────────────────────────────────── */
    var posts       = [];
    var currentPage = 1;
    var sortField   = 'date';
    var sortDir     = 'desc';
    var selectedPost = null; // full post object currently previewed

    /* ── Font Map for Canvas ────────────────────── */
    var fontCSS = {
        'Noto Serif Bengali': '"Noto Serif Bengali", serif',
        'Hind Siliguri':      '"Hind Siliguri", sans-serif',
        'SolaimanLipi':       '"SolaimanLipi", sans-serif',
        'Siyam Rupali':       '"Siyam Rupali", sans-serif'
    };

    /* ============================================================
       Bangla helpers
       ============================================================ */
    function toBanglaDigits(str) {
        var d = cpicData.banglaDigits;
        return String(str).replace(/[0-9]/g, function (m) {
            return d[parseInt(m, 10)];
        });
    }

    function toBanglaDate(dateStr) {
        if (!dateStr) return '';
        // Parse Y-M-D literally so the day never shifts with browser timezone.
        var match = String(dateStr).match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (match) {
            var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            var monthIndex = parseInt(match[2], 10);
            if (monthIndex >= 1 && monthIndex <= 12) {
                var month = months[monthIndex - 1];
                var bnMonth = cpicData.banglaMonths[month] || month;
                return toBanglaDigits(parseInt(match[3], 10)) + ' ' + bnMonth + ' ' + toBanglaDigits(parseInt(match[1], 10));
            }
        }
        var dt    = new Date(dateStr + 'T00:00:00');
        var day   = dt.getDate();
        var monthName = dt.toLocaleString('en-US', { month: 'long' });
        var year  = dt.getFullYear();
        var bnMonthFallback = cpicData.banglaMonths[monthName] || monthName;
        return toBanglaDigits(day) + ' ' + bnMonthFallback + ' ' + toBanglaDigits(year);
    }

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

    function cpicAjaxForm(action, formData, onSuccess, onError) {
        formData.append('action', action);
        formData.append(nf, nonce);

        $.ajax({
            url: ajax,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    if (onSuccess) onSuccess(res.data);
                } else {
                    var msg = (res.data && res.data.message) ? res.data.message : i18n.error;
                    if (onError) onError(msg);
                    else alert(msg);
                }
            },
            error: function () {
                if (onError) onError(i18n.error);
                else alert(i18n.error);
            }
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

    /* ============================================================
       Canvas Preview
       ============================================================ */
    function getVal(id) {
        return $('#' + id).val();
    }
    function getIntVal(id) {
        return parseInt($('#' + id).val(), 10) || 0;
    }

    function renderCanvas(onComplete) {
        var canvas = document.getElementById('cpic-canvas');
        if (!canvas) {
            if (onComplete) onComplete();
            return;
        }

        var cw = getIntVal('cpic_canvas_width')  || 1080;
        var ch = getIntVal('cpic_canvas_height') || 1080;
        canvas.width  = cw;
        canvas.height = ch;
        var ctx = canvas.getContext('2d');

        // Clear
        ctx.clearRect(0, 0, cw, ch);

        // Draw layers in order
        drawBackground(ctx, cw, ch, function () {
            drawFeaturedImage(ctx, function () {
                drawTitle(ctx, cw);
                drawDate(ctx);
                drawLogo(ctx, onComplete);
            });
        });
    }

    var serverPreviewUrl = '';

    /**
     * Render the production photocard for a post server-side (template
     * resolved by category) and show it in the preview area. Falls back to
     * the legacy client-side canvas preview if the server render fails.
     */
    function previewPostServer(postId) {
        postId = parseInt(postId, 10) || 0;
        if (!postId) { renderCanvas(); return; }

        var post = posts.find(function (p) { return p.id === postId; });
        if (post) { selectedPost = post; }

        var $canvas = $('#cpic-canvas');
        var $img    = $('#cpic-server-preview');
        var $info   = $('#cpic-preview-info');

        serverPreviewUrl = '';
        $canvas.hide();
        $img.hide();
        $info.show().text(i18n.processing || 'Rendering template preview…');

        cpicAjax('cpic_preview_post', { post_id: postId }, function (d) {
            serverPreviewUrl = d.url;
            $img.attr('src', d.url + '?t=' + Date.now()).show();
            var info = (d.template_name || 'Template') + (d.text_mode ? ' — text: ' + d.text_mode : '');
            $info.text(info).show();
        }, function (msg) {
            $info.hide();
            $canvas.show();
            renderCanvas();
            logStatus('Template preview failed: ' + msg, 'error');
        });
    }

    /* ── 1. Background ──────────────────────── */
    function drawBackground(ctx, cw, ch, cb) {
        var bgUrl = $('#cpic-bg-selector').val();
        if (bgUrl) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () {
                ctx.drawImage(img, 0, 0, cw, ch);
                cb();
            };
            img.onerror = function () {
                drawGradient(ctx, cw, ch);
                cb();
            };
            img.src = bgUrl;
        } else {
            drawGradient(ctx, cw, ch);
            cb();
        }
    }
    function drawGradient(ctx, w, h) {
        var grad = ctx.createLinearGradient(0, 0, w, h);
        grad.addColorStop(0, '#667eea');
        grad.addColorStop(1, '#764ba2');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, w, h);
    }

    /* ── 2. Featured Image ──────────────────── */
    function drawFeaturedImage(ctx, cb) {
        if (!selectedPost || !selectedPost.featured_image) { cb(); return; }
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            ctx.drawImage(
                img,
                getIntVal('cpic_feat_offset_x'),
                getIntVal('cpic_feat_offset_y'),
                getIntVal('cpic_feat_width')  || 1080,
                getIntVal('cpic_feat_height') || 650
            );
            cb();
        };
        img.onerror = function () { cb(); };
        img.src = selectedPost.featured_image;
    }

    /* ── 3. Title ───────────────────────────── */
    function drawTitle(ctx, canvasW) {
        if (!selectedPost) return;
        var text  = selectedPost.title;
        var font  = getVal('cpic_title_font') || 'Noto Serif Bengali';
        var size  = getIntVal('cpic_title_size') || 42;
        var color = getVal('cpic_title_color') || '#FFFFFF';
        var x     = getIntVal('cpic_title_offset_x');
        var y     = getIntVal('cpic_title_offset_y');

        ctx.font      = 'bold ' + size + 'px ' + (fontCSS[font] || font);
        ctx.fillStyle = color;
        ctx.textBaseline = 'top';

        var maxW  = canvasW - x - 40;
        var lines = wrapText(ctx, text, maxW);
        for (var i = 0; i < lines.length; i++) {
            ctx.fillText(lines[i], x, y + i * (size * 1.3));
        }
    }

    function wrapText(ctx, text, maxW) {
        var words = text.split(/\s+/);
        var lines = [];
        var line  = '';
        for (var i = 0; i < words.length; i++) {
            var test = line ? line + ' ' + words[i] : words[i];
            if (ctx.measureText(test).width > maxW && line) {
                lines.push(line);
                line = words[i];
            } else {
                line = test;
            }
        }
        if (line) lines.push(line);
        return lines;
    }

    /* ── 4. Date ────────────────────────────── */
    function drawDate(ctx) {
        if (!selectedPost) return;
        var dateStr = selectedPost.bangla_date;
        var font    = getVal('cpic_date_font') || 'Hind Siliguri';
        var color   = getVal('cpic_date_color') || '#FFFFFF';
        var bgColor = getVal('cpic_date_bg_color') || '#E53935';
        var x       = getIntVal('cpic_date_offset_x');
        var y       = getIntVal('cpic_date_offset_y');

        ctx.font         = '600 28px ' + (fontCSS[font] || font);
        ctx.textBaseline = 'top';

        var metrics = ctx.measureText(dateStr);
        var pad     = 16;

        // BG rect
        ctx.fillStyle = bgColor;
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(x - pad, y - pad, metrics.width + pad * 2, 28 + pad * 2, 6);
        } else {
            ctx.rect(x - pad, y - pad, metrics.width + pad * 2, 28 + pad * 2);
        }
        ctx.fill();

        // Text
        ctx.fillStyle = color;
        ctx.fillText(dateStr, x, y);
    }

    /* ── 5. Logo ────────────────────────────── */
    function drawLogo(ctx, onComplete) {
        var logoUrl = getVal('cpic_logo');
        if (!logoUrl) {
            if (onComplete) onComplete();
            return;
        }
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            var lw    = getIntVal('cpic_logo_width') || 120;
            var ratio = img.height / (img.width || 1);
            var lh    = Math.round(lw * ratio);
            ctx.drawImage(img, getIntVal('cpic_logo_offset_x'), getIntVal('cpic_logo_offset_y'), lw, lh);
            if (onComplete) onComplete();
        };
        img.onerror = function () {
            if (onComplete) onComplete();
        };
        img.src = logoUrl;
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
        data.cpic_auto_publish = $('#cpic_auto_publish').is(':checked') ? 1 : 0;
        data.cpic_auto_fb      = $('#cpic_auto_fb').is(':checked') ? 1 : 0;
        data.cpic_auto_tg      = $('#cpic_auto_tg').is(':checked') ? 1 : 0;
        data.cpic_auto_generate = $('#cpic_auto_generate').is(':checked') ? 1 : 0;
        data.cpic_auto_comment  = $('#cpic_auto_comment').is(':checked') ? 1 : 0;
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
       WordPress Media Uploader
       ============================================================ */
    function openMediaUploader(callback) {
        var frame = wp.media({
            title: 'Select Image',
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            callback(attachment.url);
        });
        frame.open();
    }

    /* ============================================================
       Background Management
       ============================================================ */
    function getBackgrounds() {
        var bgs = [];
        $('#cpic-bg-gallery .cpic-bg-item').each(function () {
            bgs.push($(this).data('url'));
        });
        return bgs;
    }

    function saveBackgrounds() {
        cpicAjax('cpic_save_backgrounds', { backgrounds: getBackgrounds() }, function (d) {
            refreshBgSelector(d.backgrounds);
        });
    }

    function refreshBgSelector(bgs) {
        var $sel = $('#cpic-bg-selector');
        var oldVal = $sel.val() || $('#cpic_active_background').val();
        $sel.find('option:not(:first)').remove();
        if (bgs) {
            $.each(bgs, function (i, url) {
                $sel.append('<option value="' + url + '">Background ' + (i + 1) + '</option>');
            });
        }
        if (oldVal) {
            $sel.val(oldVal);
            $('#cpic_active_background').val(oldVal);
        }
        renderCanvas();
    }

    /* ============================================================
       Single Download
       ============================================================ */
    function downloadCanvas() {
        if (serverPreviewUrl) {
            var a = document.createElement('a');
            a.href = serverPreviewUrl;
            a.download = 'cpic-image-' + (selectedPost ? selectedPost.id : '') + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            return;
        }
        var canvas = document.getElementById('cpic-canvas');
        var link   = document.createElement('a');
        link.download = 'cpic-image.png';
        link.href     = canvas.toDataURL('image/png');
        link.click();
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

    function getCanvasDataUrl() {
        var canvas = document.getElementById('cpic-canvas');
        if (!canvas) {
            return '';
        }
        try {
            return canvas.toDataURL('image/png');
        } catch (e) {
            return '';
        }
    }

    function prepareCanvasForPost(postId, done) {
        var post = posts.find(function (p) { return p.id === postId; });
        if (post) {
            selectedPost = post;
        }
        renderCanvas(function () {
            window.setTimeout(function () {
                done(document.getElementById('cpic-canvas'), getCanvasDataUrl());
            }, 60);
        });
    }

    function sendCanvasShareRequest(action, postId, settingsData, onSuccess, onError) {
        prepareCanvasForPost(postId, function (canvas, canvasData) {
            if (canvas && canvas.toBlob && window.FormData) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        cpicAjax(action, {
                            post_id: postId,
                            canvas_data: canvasData,
                            settings_overrides: JSON.stringify(settingsData)
                        }, onSuccess, onError);
                        return;
                    }

                    var formData = new FormData();
                    formData.append('post_id', postId);
                    formData.append('settings_overrides', JSON.stringify(settingsData));
                    formData.append('canvas_file', blob, 'cpic-preview-' + postId + '.png');

                    cpicAjaxForm(action, formData, onSuccess, onError);
                }, 'image/png');
                return;
            }

            cpicAjax(action, {
                post_id: postId,
                canvas_data: canvasData,
                settings_overrides: JSON.stringify(settingsData)
            }, onSuccess, onError);
        });
    }

    function shareToFacebook(postId) {
        saveSettings(function (settingsData) {
            sendCanvasShareRequest('cpic_fb_share', postId, settingsData, function (d) {
                logStatus(d.message, 'success');
                loadPosts(currentPage);
            }, function (msg) {
                logStatus(msg, 'error');
            });
        });
    }

    function shareToTelegram(postId) {
        saveSettings(function (settingsData) {
            sendCanvasShareRequest('cpic_tg_share', postId, settingsData, function (d) {
                logStatus(d.message, 'success');
                loadPosts(currentPage);
            }, function (msg) {
                logStatus(msg, 'error');
            });
        });
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
       Live preview on input change
       ============================================================ */
    function bindLivePreview() {
        var controls = [
            'cpic_canvas_width', 'cpic_canvas_height',
            'cpic_title_font', 'cpic_title_size', 'cpic_title_color',
            'cpic_title_offset_x', 'cpic_title_offset_y',
            'cpic_date_font', 'cpic_date_color', 'cpic_date_bg_color',
            'cpic_date_offset_x', 'cpic_date_offset_y',
            'cpic_feat_offset_x', 'cpic_feat_offset_y',
            'cpic_feat_width', 'cpic_feat_height',
            'cpic_logo', 'cpic_logo_width', 'cpic_logo_offset_x', 'cpic_logo_offset_y',
            'cpic-bg-selector'
        ];
        $.each(controls, function (_, id) {
            $('#' + id).on('input change', function () {
                renderCanvas();
            });
        });

        // Title size label
        $('#cpic_title_size').on('input', function () {
            $('#cpic_title_size_val').text($(this).val() + 'px');
        });
    }

    /* ============================================================
       Init
       ============================================================ */
    $(document).ready(function () {
        initDarkMode();

        // Color pickers
        $('.cpic-color-picker').wpColorPicker({ change: function () { setTimeout(renderCanvas, 50); } });

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
        $('#cpic-download-btn').on('click', downloadCanvas);

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

        // Logo upload
        $('#cpic-upload-logo').on('click', function () {
            openMediaUploader(function (url) {
                $('#cpic_logo').val(url);
                $('#cpic-logo-img').attr('src', url).show();
                renderCanvas();
            });
        });
        $('#cpic-remove-logo').on('click', function () {
            $('#cpic_logo').val('');
            $('#cpic-logo-img').hide();
            renderCanvas();
        });

        // Background add
        $('#cpic-add-bg').on('click', function () {
            openMediaUploader(function (url) {
                var html = '<div class="cpic-bg-item" data-url="' + url + '">' +
                    '<img src="' + url + '" alt="bg" />' +
                    '<button type="button" class="cpic-bg-remove" title="Remove">&times;</button></div>';
                $('#cpic-bg-gallery').append(html);
                saveBackgrounds();
            });
        });
        $(document).on('click', '.cpic-bg-remove', function (e) {
            e.stopPropagation();
            $(this).closest('.cpic-bg-item').remove();
            saveBackgrounds();
        });
        $('#cpic-clear-bgs').on('click', function () {
            if (!confirm(i18n.confirmClear)) return;
            cpicAjax('cpic_clear_backgrounds', {}, function () {
                $('#cpic-bg-gallery').empty();
                refreshBgSelector([]);
            });
        });

        // Click bg item -> select
        $(document).on('click', '.cpic-bg-item', function () {
            var url = $(this).data('url');
            $('#cpic-bg-selector').val(url);
            $('#cpic_active_background').val(url);
            $('.cpic-bg-item').removeClass('active');
            $(this).addClass('active');
            renderCanvas();
        });

        // Restore saved active background
        var savedBg = S.cpic_active_background || '';
        if (savedBg) {
            $('#cpic-bg-selector').val(savedBg);
            $('.cpic-bg-item').each(function () {
                if ($(this).data('url') === savedBg) {
                    $(this).addClass('active');
                }
            });
        }

        // When bg selector dropdown changes, store value in hidden field
        $('#cpic-bg-selector').on('change', function () {
            $('#cpic_active_background').val($(this).val());
        });

        initFacebook();
        initTelegram();
        bindLivePreview();
        initQueueMonitor();
    });

    /* Expose for bulk-processing.js */
    window.cpicApp = {
        getSelectedPostIds: getSelectedPostIds,
        posts:              function () { return posts; },
        renderCanvas:       renderCanvas,
        logStatus:          logStatus,
        loadPosts:          function () { loadPosts(currentPage); },
        ajax:               cpicAjax,
        ajaxForm:           cpicAjaxForm,
        escHtml:            escHtml
    };

})(jQuery);
