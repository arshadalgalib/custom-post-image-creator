/**
 * Custom Post Image Creator v5.2 – Bulk Processing
 *
 * Handles:
 *   - Bulk ZIP download (native, no external libraries)
 *   - Bulk Facebook share
 *   - Bulk Telegram share
 *   - Progress bar
 */

/* global jQuery, cpicData */
(function ($) {
    'use strict';

    var app  = window.cpicApp;
    var i18n = cpicData.i18n;

    /* ============================================================
       Minimal ZIP writer (STORE method, no external libraries)
       ============================================================ */
    var CRC_TABLE = (function () {
        var table = new Uint32Array(256);
        for (var i = 0; i < 256; i++) {
            var c = i;
            for (var k = 0; k < 8; k++) {
                c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
            }
            table[i] = c >>> 0;
        }
        return table;
    })();

    function crc32(buf) {
        var c = 0xFFFFFFFF;
        for (var i = 0; i < buf.length; i++) {
            c = (CRC_TABLE[(c ^ buf[i]) & 0xFF] ^ (c >>> 8)) >>> 0;
        }
        return (c ^ 0xFFFFFFFF) >>> 0;
    }

    function dosDateTime(date) {
        return {
            time: (((date.getHours() << 11) | (date.getMinutes() << 5) | (date.getSeconds() >> 1)) & 0xFFFF),
            date: (((Math.max(1980, date.getFullYear()) - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate()) & 0xFFFF
        };
    }

    /**
     * Build a ZIP blob from name/data pairs (STORE method).
     *
     * @param {Array<{name:string, data:Uint8Array}>} entries
     * @return {Blob}
     */
    function createZipBlob(entries) {
        var encoder  = new TextEncoder();
        var offset   = 0;
        var local    = [];
        var central  = [];

        entries.forEach(function (entry) {
            var nameBytes = encoder.encode(entry.name);
            var crc = crc32(entry.data);
            var dos = dosDateTime(new Date());
            var size = entry.data.length;
            var method = 0; // STORE

            // Local file header (30 bytes).
            var lh = new Uint8Array(30);
            var ldv = new DataView(lh.buffer);
            ldv.setUint32(0, 0x04034b50, true);
            ldv.setUint16(4, 20, true);     // version needed to extract
            ldv.setUint16(6, 0x0800, true); // general purpose bit flag (UTF-8)
            ldv.setUint16(8, method, true);
            ldv.setUint16(10, dos.time, true);
            ldv.setUint16(12, dos.date, true);
            ldv.setUint32(14, crc, true);
            ldv.setUint32(18, size, true);
            ldv.setUint32(22, size, true);
            ldv.setUint16(26, nameBytes.length, true);
            ldv.setUint16(28, 0, true);
            local.push(lh, nameBytes, entry.data);

            // Central directory header (46 bytes).
            var ch = new Uint8Array(46);
            var cdv = new DataView(ch.buffer);
            cdv.setUint32(0, 0x02014b50, true);
            cdv.setUint16(4, 20, true);
            cdv.setUint16(6, 20, true);
            cdv.setUint16(8, 0x0800, true);
            cdv.setUint16(10, method, true);
            cdv.setUint16(12, dos.time, true);
            cdv.setUint16(14, dos.date, true);
            cdv.setUint32(16, crc, true);
            cdv.setUint32(20, size, true);
            cdv.setUint32(24, size, true);
            cdv.setUint16(28, nameBytes.length, true);
            cdv.setUint16(30, 0, true);
            cdv.setUint16(32, 0, true);
            cdv.setUint16(34, 0, true);
            cdv.setUint16(36, 0, true);
            cdv.setUint32(38, 0, true);
            cdv.setUint32(42, offset, true);
            central.push(ch, nameBytes);

            offset += 30 + nameBytes.length + size;
        });

        var centralSize = 0;
        central.forEach(function (part) { centralSize += part.length; });

        // End of central directory record (22 bytes).
        var eocd = new Uint8Array(22);
        var edv = new DataView(eocd.buffer);
        edv.setUint32(0, 0x06054b50, true);
        edv.setUint16(8, entries.length, true);
        edv.setUint16(10, entries.length, true);
        edv.setUint32(12, centralSize, true);
        edv.setUint32(16, offset, true);
        edv.setUint16(20, 0, true);

        var parts = local.concat(central);
        parts.push(eocd);

        var total = 0;
        parts.forEach(function (part) { total += part.length; });
        var out = new Uint8Array(total);
        var pos = 0;
        parts.forEach(function (part) {
            out.set(part, pos);
            pos += part.length;
        });

        return new Blob([out], { type: 'application/zip' });
    }

    function triggerDownload(blob, filename) {
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 1000);
    }

    function dataUrlToUint8Array(dataUrl) {
        var byteString = atob(dataUrl.split(',')[1]);
        var len = byteString.length;
        var arr = new Uint8Array(len);
        for (var i = 0; i < len; i++) {
            arr[i] = byteString.charCodeAt(i);
        }
        return arr;
    }

    /* ── Progress helpers ───────────────────── */
    function showProgress() {
        $('#cpic-progress').show();
        setProgress(0, '');
    }
    function hideProgress() {
        $('#cpic-progress').fadeOut(600);
    }
    function setProgress(pct, text) {
        $('#cpic-progress-fill').css('width', pct + '%');
        $('#cpic-progress-text').text(text);
    }

    /* ============================================================
       Bulk ZIP Download
       ============================================================ */
    function bulkDownload() {
        var ids = app.getSelectedPostIds();
        if (!ids.length) { alert(i18n.noSelection); return; }

        var allPosts = app.posts();
        var selected = allPosts.filter(function (p) { return ids.indexOf(p.id) !== -1; });
        var total    = selected.length;
        var done     = 0;
        var files    = [];

        showProgress();

        function processNext() {
            if (done >= total) {
                setProgress(100, 'Creating ZIP…');
                var zip = createZipBlob(files);
                triggerDownload(zip, 'cpic-images.zip');
                hideProgress();
                app.logStatus('ZIP downloaded with ' + total + ' images.', 'success');
                return;
            }

            var post = selected[done];
            setProgress(Math.round((done / total) * 100), 'Processing Post ' + (done + 1) + ' of ' + total);

            renderCanvasForPost(post, function (dataUrl) {
                files.push({ name: 'cpic-' + post.id + '.png', data: dataUrlToUint8Array(dataUrl) });
                done++;
                processNext();
            });
        }

        processNext();
    }

    /**
     * Render canvas for a specific post and return data URL via callback.
     */
    function renderCanvasForPost(post, callback) {
        var canvas = document.getElementById('cpic-canvas');
        var ctx    = canvas.getContext('2d');
        var cw     = parseInt($('#cpic_canvas_width').val(), 10) || 1080;
        var ch     = parseInt($('#cpic_canvas_height').val(), 10) || 1080;
        canvas.width  = cw;
        canvas.height = ch;
        ctx.clearRect(0, 0, cw, ch);

        var bgUrl = $('#cpic-bg-selector').val();

        var fontCSS = {
            'Noto Serif Bengali': '"Noto Serif Bengali", serif',
            'Hind Siliguri':      '"Hind Siliguri", sans-serif',
            'SolaimanLipi':       '"SolaimanLipi", sans-serif',
            'Siyam Rupali':       '"Siyam Rupali", sans-serif'
        };

        function gv(id) { return $('#' + id).val(); }
        function gi(id) { return parseInt($('#' + id).val(), 10) || 0; }

        function wrapText(ctx2, text, maxW) {
            var words = text.split(/\s+/);
            var lines = [], line = '';
            for (var i = 0; i < words.length; i++) {
                var test = line ? line + ' ' + words[i] : words[i];
                if (ctx2.measureText(test).width > maxW && line) {
                    lines.push(line);
                    line = words[i];
                } else {
                    line = test;
                }
            }
            if (line) lines.push(line);
            return lines;
        }

        function drawLayers() {
            // Featured image
            var drawRest = function () {
                // Title
                var font  = gv('cpic_title_font') || 'Noto Serif Bengali';
                var size  = gi('cpic_title_size') || 42;
                ctx.font      = 'bold ' + size + 'px ' + (fontCSS[font] || font);
                ctx.fillStyle = gv('cpic_title_color') || '#FFFFFF';
                ctx.textBaseline = 'top';
                var x = gi('cpic_title_offset_x'), y = gi('cpic_title_offset_y');
                var lines = wrapText(ctx, post.title, cw - x - 40);
                for (var i = 0; i < lines.length; i++) {
                    ctx.fillText(lines[i], x, y + i * (size * 1.3));
                }

                // Date
                var dateFont = gv('cpic_date_font') || 'Hind Siliguri';
                ctx.font = '600 28px ' + (fontCSS[dateFont] || dateFont);
                ctx.textBaseline = 'top';
                var dx = gi('cpic_date_offset_x'), dy = gi('cpic_date_offset_y');
                var metrics = ctx.measureText(post.bangla_date);
                var pad = 16;
                ctx.fillStyle = gv('cpic_date_bg_color') || '#E53935';
                ctx.beginPath();
                if (ctx.roundRect) {
                    ctx.roundRect(dx - pad, dy - pad, metrics.width + pad * 2, 28 + pad * 2, 6);
                } else {
                    ctx.rect(dx - pad, dy - pad, metrics.width + pad * 2, 28 + pad * 2);
                }
                ctx.fill();
                ctx.fillStyle = gv('cpic_date_color') || '#FFFFFF';
                ctx.fillText(post.bangla_date, dx, dy);

                // Logo
                var logoUrl = gv('cpic_logo');
                if (logoUrl) {
                    var logoImg = new Image();
                    logoImg.crossOrigin = 'anonymous';
                    logoImg.onload = function () {
                        var lw = gi('cpic_logo_width') || 120;
                        var ratio = logoImg.height / (logoImg.width || 1);
                        ctx.drawImage(logoImg, gi('cpic_logo_offset_x'), gi('cpic_logo_offset_y'), lw, Math.round(lw * ratio));
                        callback(canvas.toDataURL('image/png'));
                    };
                    logoImg.onerror = function () {
                        callback(canvas.toDataURL('image/png'));
                    };
                    logoImg.src = logoUrl;
                } else {
                    callback(canvas.toDataURL('image/png'));
                }
            };

            if (post.featured_image) {
                var featImg = new Image();
                featImg.crossOrigin = 'anonymous';
                featImg.onload = function () {
                    ctx.drawImage(featImg, gi('cpic_feat_offset_x'), gi('cpic_feat_offset_y'), gi('cpic_feat_width') || 1080, gi('cpic_feat_height') || 650);
                    drawRest();
                };
                featImg.onerror = drawRest;
                featImg.src = post.featured_image;
            } else {
                drawRest();
            }
        }

        // Background
        if (bgUrl) {
            var bgImg = new Image();
            bgImg.crossOrigin = 'anonymous';
            bgImg.onload = function () {
                ctx.drawImage(bgImg, 0, 0, cw, ch);
                drawLayers();
            };
            bgImg.onerror = function () {
                var grad = ctx.createLinearGradient(0, 0, cw, ch);
                grad.addColorStop(0, '#667eea');
                grad.addColorStop(1, '#764ba2');
                ctx.fillStyle = grad;
                ctx.fillRect(0, 0, cw, ch);
                drawLayers();
            };
            bgImg.src = bgUrl;
        } else {
            var grad = ctx.createLinearGradient(0, 0, cw, ch);
            grad.addColorStop(0, '#667eea');
            grad.addColorStop(1, '#764ba2');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, cw, ch);
            drawLayers();
        }
    }

    /* ============================================================
       Bulk Facebook Share
       ============================================================ */
    function bulkFacebook() {
        var ids = app.getSelectedPostIds();
        if (!ids.length) { alert(i18n.noSelection); return; }

        var total = ids.length;
        var done  = 0;
        showProgress();

        function next() {
            if (done >= total) {
                hideProgress();
                app.loadPosts();
                return;
            }
            setProgress(Math.round((done / total) * 100), 'Posting to Facebook ' + (done + 1) + ' of ' + total + '...');

            var postId = ids[done];
            sendBulkShare('cpic_fb_share', postId, function () {
                app.logStatus('Post ' + postId + ': Posted to Facebook.', 'success');
                done++;
                setTimeout(next, 2000);
            }, function (msg) {
                app.logStatus('Post ' + postId + ': ' + msg, 'error');
                done++;
                setTimeout(next, 2000);
            });
        }

        next();
    }

    /* ============================================================
       Bulk Telegram Share
       ============================================================ */
    function bulkTelegram() {
        var ids = app.getSelectedPostIds();
        if (!ids.length) { alert(i18n.noSelection); return; }

        var total = ids.length;
        var done  = 0;
        showProgress();

        function next() {
            if (done >= total) {
                hideProgress();
                app.loadPosts();
                return;
            }
            setProgress(Math.round((done / total) * 100), 'Sending to Telegram ' + (done + 1) + ' of ' + total + '...');

            var postId = ids[done];
            sendBulkShare('cpic_tg_share', postId, function () {
                app.logStatus('Post ' + postId + ': Sent to Telegram.', 'success');
                done++;
                setTimeout(next, 1500);
            }, function (msg) {
                app.logStatus('Post ' + postId + ': ' + msg, 'error');
                done++;
                setTimeout(next, 1500);
            });
        }

        next();
    }

    /**
     * Send a bulk share request by rendering canvas and uploading.
     */
    function sendBulkShare(action, postId, onSuccess, onError) {
        var post = findPostById(postId);
        if (!post) {
            onError('Post data not found.');
            return;
        }

        renderCanvasForPost(post, function (dataUrl) {
            var blob = new Blob([dataUrlToUint8Array(dataUrl)], { type: 'image/png' });

            var formData = new FormData();
            formData.append('post_id', postId);
            formData.append('canvas_file', blob, 'cpic-bulk-' + postId + '.png');

            app.ajaxForm(action, formData, function (d) {
                onSuccess(d);
            }, function (msg) {
                onError(msg);
            });
        });
    }

    /**
     * Find a post by ID from the loaded posts.
     */
    function findPostById(postId) {
        var allPosts = app.posts();
        return allPosts.find(function (p) { return p && p.id === postId; });
    }

    /* ============================================================
       Bind buttons
       ============================================================ */
    $(document).ready(function () {
        $('#cpic-bulk-download').on('click', bulkDownload);
        $('#cpic-bulk-fb').on('click', bulkFacebook);
        $('#cpic-bulk-tg').on('click', bulkTelegram);
    });

})(jQuery);
