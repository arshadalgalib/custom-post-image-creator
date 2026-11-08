/* global jQuery, cpicData, CPICCanvasRenderer, wp */
(function ($, root) {
    'use strict';
    if (!root.cpicData || !root.CPICCanvasRenderer) return;
    var cfg = root.cpicData;

    function request(action, params, file, fileField) {
        var form = new FormData();
        form.append('action', action);
        form.append(cfg.nonceField, cfg.nonce);
        Object.keys(params || {}).forEach(function (key) { form.append(key, params[key]); });
        if (file) form.append(fileField || 'image', file, 'photocard.png');
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (json) {
                if (!response.ok || !json || !json.success) {
                    var message = json && json.data && json.data.message ? json.data.message : (cfg.i18n.error || 'Request failed.');
                    throw new Error(message + (response.ok ? '' : ' (HTTP ' + response.status + ')'));
                }
                return json.data;
            });
        });
    }

    function draw(config, data, hashes) {
        var canvas = document.createElement('canvas');
        return root.CPICCanvasRenderer.render(canvas, config, data, {
            pluginUrl: cfg.pluginUrl,
            templateHash: hashes && hashes.template_hash,
            inputHash: hashes && hashes.input_hash
        }).then(function () { return canvas; });
    }

    function upload(canvas, context) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (!blob) { reject(new Error('Browser could not encode the photocard as PNG.')); return; }
                var chunkSize = 256 * 1024;
                if (blob.size < 24 || blob.size > 15 * 1024 * 1024) { reject(new Error('The generated PNG must be 15 MB or smaller.')); return; }
                var chunkCount = Math.ceil(blob.size / chunkSize);
                var transferId = '';
                if (root.crypto && root.crypto.getRandomValues) {
                    var random = new Uint8Array(16);
                    root.crypto.getRandomValues(random);
                    transferId = Array.prototype.map.call(random, function (value) { return value.toString(16).padStart(2, '0'); }).join('');
                } else {
                    transferId = (Date.now().toString(16) + Math.random().toString(16).slice(2)).padEnd(32, '0').slice(0, 32);
                }
                var params = {
                    post_id: String(context.post_id),
                    template_hash: context.template_hash,
                    input_hash: context.input_hash,
                    transfer_id: transferId,
                    chunk_count: String(chunkCount),
                    total_size: String(blob.size)
                };
                function sendChunk(index) {
                    params.chunk_index = String(index);
                    var chunk = blob.slice(index * chunkSize, Math.min(blob.size, (index + 1) * chunkSize), 'image/png');
                    return request('cpic_store_rendered_chunk', params, chunk, 'chunk').then(function (result) {
                        return index + 1 < chunkCount ? sendChunk(index + 1) : result;
                    });
                }
                sendChunk(0).then(resolve, reject);
            }, 'image/png');
        });
    }

    function renderPost(postId) {
        return request('cpic_render_context', { post_id: String(postId) }).then(function (context) {
            if (context.existing_artifact) {
                return Object.assign({}, context.existing_artifact, { template_name: context.template_name, reused: true });
            }
            return draw(context.config, context.data, context).then(function (canvas) {
                return upload(canvas, context).then(function (saved) {
                    saved.template_name = context.template_name;
                    saved.renderer = context.renderer;
                    saved.canvas = canvas;
                    saved.reused = false;
                    return saved;
                });
            });
        });
    }

    root.cpicRenderPost = renderPost;
    root.cpicRenderTemplate = function (config, data) { return draw(config, data, {}); };
    root.cpicSaveCanvas = upload;

    function showCaptureStatus(kind, message) {
        var target = root.document.getElementById('wpbody-content') || root.document.body;
        if (!target) return;
        var notice = root.document.getElementById('cpic-canvas-capture-status');
        if (!notice) {
            notice = root.document.createElement('div');
            notice.id = 'cpic-canvas-capture-status';
            notice.setAttribute('role', 'status');
            notice.setAttribute('aria-live', 'polite');
            target.insertBefore(notice, target.firstChild);
        }
        notice.className = 'notice ' + (kind === 'error' ? 'notice-error' : (kind === 'success' ? 'notice-success' : 'notice-info'));
        notice.textContent = message;
    }

    // Capture the current post from the editor on initial load and after every save.
    // This is the browser-rendered artifact that WP-Cron later reuses; cron never
    // tries to boot a browser or silently substitutes a PHP text renderer.
    function installEditorCapture() {
        if (!root.wp || !wp.data || !wp.data.select || !wp.data.subscribe || !wp.data.select('core/editor')) {
            if (parseInt(cfg.postId, 10) > 0) window.setTimeout(function () {
                showCaptureStatus('info', cfg.i18n.canvasSaving || 'Saving browser-rendered card…');
                renderPost(parseInt(cfg.postId, 10)).then(function () {
                    showCaptureStatus('success', cfg.i18n.canvasSaved || 'Browser-rendered card saved.');
                }).catch(function (error) {
                    showCaptureStatus('error', (cfg.i18n.canvasSaveFailed || 'Could not save the browser-rendered card.') + ' ' + error.message);
                    if (root.console && console.warn) console.warn('[CPIC] Card capture failed:', error.message);
                });
            }, 1500);
            return;
        }
        var saving = false;
        var busy = false;
        function capture() {
            var editor = wp.data.select('core/editor');
            var id = editor && editor.getCurrentPostId ? parseInt(editor.getCurrentPostId(), 10) : 0;
            if (!id || busy) return;
            busy = true;
            showCaptureStatus('info', cfg.i18n.canvasSaving || 'Saving browser-rendered card…');
            renderPost(id).then(function (result) {
                showCaptureStatus('success', cfg.i18n.canvasSaved || 'Browser-rendered card saved. Automatic publishing can now reuse this image.');
                if (root.console && console.info) console.info('[CPIC] Canonical card saved.', result.url);
            }).catch(function (error) {
                showCaptureStatus('error', (cfg.i18n.canvasSaveFailed || 'Could not save the browser-rendered card.') + ' ' + error.message);
                if (root.console && console.warn) console.warn('[CPIC] Card capture failed:', error.message);
            }).then(function () { busy = false; });
        }
        wp.data.subscribe(function () {
            var editor = wp.data.select('core/editor');
            if (!editor) return;
            var isSaving = editor.isSavingPost && editor.isSavingPost();
            if (isSaving) { saving = true; return; }
            if (saving) {
                saving = false;
                var saveSucceeded = !editor.didPostSaveRequestSucceed || editor.didPostSaveRequestSucceed();
                if (saveSucceeded) capture();
            }
        });
        window.setTimeout(capture, 1200);
    }
    if (cfg.isEditor) $(installEditorCapture);
})(jQuery, window);
