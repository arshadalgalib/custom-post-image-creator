/* global jQuery, cpicEditorData, wp */
(function ($) {
    'use strict';

    var cfg = window.cpicEditorData || {};
    var S = cfg.settings || {};
    var autoEnabled = parseInt(S.cpic_auto_publish, 10) === 1;
    var hasTargets = parseInt(S.cpic_auto_fb, 10) === 1 || parseInt(S.cpic_auto_tg, 10) === 1;
    var uploadInFlight = false;
    var uploadedPosts = {};
    var watchKey = '';

    if (!autoEnabled || !hasTargets) {
        return;
    }

    if (cfg.currentPostId && cfg.currentImagePath) {
        uploadedPosts[parseInt(cfg.currentPostId, 10)] = true;
    }

    var fontCSS = {
        'Noto Serif Bengali': '"Noto Serif Bengali", serif',
        'Hind Siliguri': '"Hind Siliguri", sans-serif',
        'SolaimanLipi': '"SolaimanLipi", sans-serif',
        'Siyam Rupali': '"Siyam Rupali", sans-serif'
    };

    function getSetting(key, fallback) {
        return Object.prototype.hasOwnProperty.call(S, key) ? S[key] : fallback;
    }

    function getIntSetting(key, fallback) {
        var value = parseInt(getSetting(key, fallback), 10);
        return isNaN(value) ? fallback : value;
    }

    function ajaxForm(action, formData) {
        formData.append('action', action);
        formData.append(cfg.nonceField, cfg.nonce);

        return $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).then(function (resp) {
            if (!resp || !resp.success) {
                throw new Error(resp && resp.data && resp.data.message ? resp.data.message : 'Auto-publish upload failed.');
            }
            return resp.data || {};
        });
    }

    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            if (!url) {
                resolve(null);
                return;
            }

            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = reject;
            img.src = url;
        });
    }

    function canvasToBlob(canvas) {
        return new Promise(function (resolve, reject) {
            if (!canvas || !canvas.toBlob) {
                reject(new Error('Canvas blob export is not available.'));
                return;
            }

            canvas.toBlob(function (blob) {
                if (!blob) {
                    reject(new Error('Could not export auto-publish image.'));
                    return;
                }
                resolve(blob);
            }, 'image/png');
        });
    }

    function toBanglaDigits(str) {
        var digits = cfg.banglaDigits || [];
        return String(str).replace(/[0-9]/g, function (match) {
            return digits[parseInt(match, 10)] || match;
        });
    }

    function formatBanglaDate(dateValue) {
        var englishMonths = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        // Gutenberg gives the post date as site-local wall time without a
        // timezone (e.g. "2026-09-13T19:00:00"). Parse Y-M-D literally so the
        // day can never shift when the browser timezone differs from the site
        // timezone (new Date() + getDate() would convert between zones).
        var match = dateValue ? String(dateValue).match(/(\d{4})-(\d{1,2})-(\d{1,2})/) : null;
        if (match) {
            var year = parseInt(match[1], 10);
            var monthIndex = parseInt(match[2], 10);
            var day = parseInt(match[3], 10);
            if (monthIndex >= 1 && monthIndex <= 12 && day >= 1 && day <= 31) {
                var monthName = englishMonths[monthIndex - 1];
                var banglaMonth = (cfg.banglaMonths && cfg.banglaMonths[monthName]) ? cfg.banglaMonths[monthName] : monthName;
                return toBanglaDigits(day) + ' ' + banglaMonth + ' ' + toBanglaDigits(year);
            }
        }

        var date = dateValue ? new Date(dateValue) : new Date();
        if (isNaN(date.getTime())) {
            date = new Date();
        }

        var fallbackMonth = englishMonths[date.getMonth()];
        var fallbackLabel = (cfg.banglaMonths && cfg.banglaMonths[fallbackMonth]) ? cfg.banglaMonths[fallbackMonth] : fallbackMonth;
        return toBanglaDigits(date.getDate()) + ' ' + fallbackLabel + ' ' + toBanglaDigits(date.getFullYear());
    }

    function wrapText(ctx, text, maxWidth) {
        var words = String(text || '').split(/\s+/);
        var lines = [];
        var line = '';
        var index;
        var test;

        for (index = 0; index < words.length; index += 1) {
            test = line ? line + ' ' + words[index] : words[index];
            if (ctx.measureText(test).width > maxWidth && line) {
                lines.push(line);
                line = words[index];
            } else {
                line = test;
            }
        }

        if (line) {
            lines.push(line);
        }

        return lines;
    }

    function drawRoundedRect(ctx, x, y, width, height, radius) {
        if (ctx.roundRect) {
            ctx.beginPath();
            ctx.roundRect(x, y, width, height, radius);
            ctx.fill();
            return;
        }

        ctx.fillRect(x, y, width, height);
    }

    function drawGradient(ctx, width, height) {
        var gradient = ctx.createLinearGradient(0, 0, width, height);
        gradient.addColorStop(0, '#667eea');
        gradient.addColorStop(1, '#764ba2');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, width, height);
    }

    function getClassicFeaturedImageUrl() {
        return $('#postimagediv img').first().attr('src') || $('#set-post-thumbnail img').first().attr('src') || '';
    }

    function getClassicTitle() {
        return $('#title').val() || $('input[name="post_title"]').val() || document.title || '';
    }

    function getClassicStatus() {
        return $('#post_status').val() || $('#hidden_post_status').val() || '';
    }

    function getBlockEditorPost() {
        if (!window.wp || !wp.data || !wp.data.select) {
            return null;
        }

        var editor = wp.data.select('core/editor');
        if (!editor || !editor.getCurrentPostId) {
            return null;
        }

        return {
            id: editor.getCurrentPostId(),
            title: editor.getEditedPostAttribute('title') || '',
            status: editor.getEditedPostAttribute('status') || '',
            date: editor.getEditedPostAttribute('date') || '',
            featuredId: editor.getEditedPostAttribute('featured_media') || 0,
            isSaving: editor.isSavingPost ? editor.isSavingPost() : false,
            isAutosaving: editor.isAutosavingPost ? editor.isAutosavingPost() : false
        };
    }

    function getClassicPost() {
        return {
            id: parseInt($('#post_ID').val() || cfg.currentPostId || 0, 10),
            title: getClassicTitle(),
            status: getClassicStatus(),
            date: '',
            featuredId: 0,
            featuredImage: getClassicFeaturedImageUrl(),
            isSaving: false,
            isAutosaving: false
        };
    }

    function getEditorSnapshot() {
        var blockPost = getBlockEditorPost();
        if (blockPost) {
            return blockPost;
        }
        return getClassicPost();
    }

    function resolveFeaturedImageUrl(postState) {
        if (postState.featuredImage) {
            return Promise.resolve(postState.featuredImage);
        }

        if (!postState.featuredId) {
            return Promise.resolve('');
        }

        if (window.wp && wp.data && wp.data.select) {
            var mediaRecord = wp.data.select('core').getMedia(postState.featuredId);
            if (mediaRecord) {
                return Promise.resolve(
                    mediaRecord.source_url ||
                    (mediaRecord.media_details && mediaRecord.media_details.sizes && mediaRecord.media_details.sizes.medium && mediaRecord.media_details.sizes.medium.source_url) ||
                    ''
                );
            }
        }

        if (window.wp && wp.apiFetch) {
            return wp.apiFetch({ path: '/wp/v2/media/' + postState.featuredId }).then(function (media) {
                return media && (media.source_url || '') ? media.source_url : '';
            }).catch(function () {
                return '';
            });
        }

        return Promise.resolve('');
    }

    function buildRenderState(postState, featuredImage) {
        return {
            id: postState.id,
            title: postState.title || '',
            banglaDate: formatBanglaDate(postState.date),
            featuredImage: featuredImage || ''
        };
    }

    function renderCard(renderState) {
        var width = getIntSetting('cpic_canvas_width', 1080);
        var height = getIntSetting('cpic_canvas_height', 1080);
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        var background = getSetting('cpic_active_background', '');
        var titleFont = getSetting('cpic_title_font', 'Noto Serif Bengali');
        var dateFont = getSetting('cpic_date_font', 'Hind Siliguri');
        var titleSize = getIntSetting('cpic_title_size', 42);
        var titleX = getIntSetting('cpic_title_offset_x', 40);
        var titleY = getIntSetting('cpic_title_offset_y', 750);
        var dateX = getIntSetting('cpic_date_offset_x', 40);
        var dateY = getIntSetting('cpic_date_offset_y', 950);
        var featuredX = getIntSetting('cpic_feat_offset_x', 0);
        var featuredY = getIntSetting('cpic_feat_offset_y', 0);
        var featuredW = getIntSetting('cpic_feat_width', 1080);
        var featuredH = getIntSetting('cpic_feat_height', 650);
        var logoUrl = getSetting('cpic_logo', '');
        var logoW = getIntSetting('cpic_logo_width', 120);
        var logoX = getIntSetting('cpic_logo_offset_x', 40);
        var logoY = getIntSetting('cpic_logo_offset_y', 40);

        canvas.width = width;
        canvas.height = height;

        if (!renderState.title) {
            if (window.console && console.warn) {
                console.warn('CPIC: No title for post', renderState.id);
            }
        }
        if (!renderState.featuredImage) {
            if (window.console && console.warn) {
                console.warn('CPIC: No featured image for post', renderState.id);
            }
        }

        return Promise.all([
            loadImage(background).catch(function () { return null; }),
            loadImage(renderState.featuredImage).catch(function () { return null; }),
            loadImage(logoUrl).catch(function () { return null; })
        ]).then(function (images) {
            var bgImage = images[0];
            var featuredImage = images[1];
            var logoImage = images[2];
            var lines;
            var metrics;
            var padding;
            var logoH;

            ctx.clearRect(0, 0, width, height);

            if (bgImage) {
                ctx.drawImage(bgImage, 0, 0, width, height);
            } else {
                drawGradient(ctx, width, height);
            }

            if (featuredImage) {
                ctx.drawImage(featuredImage, featuredX, featuredY, featuredW, featuredH);
            }

            ctx.font = 'bold ' + titleSize + 'px ' + (fontCSS[titleFont] || titleFont);
            ctx.fillStyle = getSetting('cpic_title_color', '#FFFFFF');
            ctx.textBaseline = 'top';
            lines = wrapText(ctx, renderState.title, width - titleX - 40);
            lines.forEach(function (line, index) {
                ctx.fillText(line, titleX, titleY + index * (titleSize * 1.3));
            });

            ctx.font = '600 28px ' + (fontCSS[dateFont] || dateFont);
            ctx.textBaseline = 'top';
            metrics = ctx.measureText(renderState.banglaDate);
            padding = 16;
            ctx.fillStyle = getSetting('cpic_date_bg_color', '#E53935');
            drawRoundedRect(ctx, dateX - padding, dateY - padding, metrics.width + padding * 2, 28 + padding * 2, 6);
            ctx.fillStyle = getSetting('cpic_date_color', '#FFFFFF');
            ctx.fillText(renderState.banglaDate, dateX, dateY);

            if (logoImage) {
                logoH = Math.round(logoW * (logoImage.height / Math.max(logoImage.width, 1)));
                ctx.drawImage(logoImage, logoX, logoY, logoW, logoH);
            }

            return canvas;
        });
    }

    function maybeStoreAutoImage() {
        var postState = getEditorSnapshot();

        if (uploadInFlight || !postState.id || postState.status !== 'publish' || uploadedPosts[postState.id]) {
            return;
        }

        uploadInFlight = true;

        resolveFeaturedImageUrl(postState)
            .then(function (featuredImage) {
                return renderCard(buildRenderState(postState, featuredImage));
            })
            .then(function (canvas) {
                return canvasToBlob(canvas);
            })
            .then(function (blob) {
                var formData = new FormData();
                formData.append('post_id', postState.id);
                formData.append('canvas_file', blob, 'cpic-auto-' + postState.id + '.png');
                return ajaxForm('cpic_store_auto_image', formData);
            })
            .then(function () {
                uploadedPosts[postState.id] = true;
            })
            .catch(function (error) {
                if (window.console && console.error) {
                    console.error('CPIC auto-publish image capture failed:', error);
                }
            })
            .finally(function () {
                uploadInFlight = false;
            });
    }

    function watchBlockEditor() {
        if (!window.wp || !wp.data || !wp.data.subscribe) {
            return;
        }

        wp.data.subscribe(function () {
            var snapshot = getBlockEditorPost();
            var nextKey;

            if (!snapshot) {
                return;
            }

            nextKey = [snapshot.id, snapshot.status, snapshot.isSaving ? 1 : 0, snapshot.isAutosaving ? 1 : 0].join(':');
            if (nextKey === watchKey) {
                return;
            }
            watchKey = nextKey;

            if (snapshot.id && snapshot.status === 'publish' && !snapshot.isSaving && !snapshot.isAutosaving) {
                window.setTimeout(maybeStoreAutoImage, 1200);
            }
        });
    }

    $(function () {
        window.setTimeout(maybeStoreAutoImage, 1500);
        watchBlockEditor();
    });
})(jQuery);