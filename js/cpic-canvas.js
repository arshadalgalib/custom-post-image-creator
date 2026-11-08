/* global jQuery, cpicData, CPICCanvasRenderer, wp */
(function ($, root) {
    'use strict';
    if (!root.cpicData || !root.CPICCanvasRenderer) return;
    var cfg = root.cpicData;

    function request(action, params, file) {
        var form = new FormData();
        form.append('action', action);
        form.append(cfg.nonceField, cfg.nonce);
        Object.keys(params || {}).forEach(function (key) { form.append(key, params[key]); });
        if (file) form.append('image', file, 'photocard.png');
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form }).then(function (response) {
            if (!response.ok) throw new Error('Request failed (' + response.status + ').');
            return response.json();
        }).then(function (json) {
            if (!json || !json.success) throw new Error(json && json.data && json.data.message ? json.data.message : (cfg.i18n.error || 'Request failed.'));
            return json.data;
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
                request('cpic_store_rendered_image', {
                    post_id: String(context.post_id),
                    template_hash: context.template_hash,
                    input_hash: context.input_hash
                }, blob).then(resolve, reject);
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

    // Capture the current post from the editor on initial load and after every save.
    // This is the browser-rendered artifact that WP-Cron later reuses; cron never
    // tries to boot a browser or silently substitutes a PHP text renderer.
    function installEditorCapture() {
        if (!root.wp || !wp.data || !wp.data.select || !wp.data.subscribe || !wp.data.select('core/editor')) {
            if (parseInt(cfg.postId, 10) > 0) window.setTimeout(function () { renderPost(parseInt(cfg.postId, 10)).catch(function (error) { if (root.console && console.warn) console.warn('[CPIC] Card capture failed:', error.message); }); }, 1500);
            return;
        }
        var saving = false;
        var busy = false;
        function capture() {
            var editor = wp.data.select('core/editor');
            var id = editor && editor.getCurrentPostId ? parseInt(editor.getCurrentPostId(), 10) : 0;
            if (!id || busy) return;
            busy = true;
            renderPost(id).then(function (result) {
                if (root.console && console.info) console.info('[CPIC] Canonical card saved.', result.url);
            }).catch(function (error) {
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
