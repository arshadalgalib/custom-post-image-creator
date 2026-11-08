/**
 * Template Manager UI – list actions, dynamic editor, server-side preview.
 *
 * Preview uses the real server renderer with sample data, so what you see
 * in the preview is exactly what the automatic pipeline will produce.
 */

/* global jQuery, cpicData, wp */
(function ($) {
    'use strict';

    var ajax  = cpicData.ajaxUrl;
    var nonce = cpicData.nonce;
    var nf    = cpicData.nonceField;
    var i18n  = cpicData.i18n;
    var fonts = cpicData.fontChoices || ['Noto Serif Bengali', 'Hind Siliguri', 'Noto Sans Bengali'];

    var currentTemplate = null; // { id, name, config, ... }

    function cpicAjax(action, data, onSuccess, onError) {
        data = data || {};
        data.action = action;
        data[nf]    = nonce;
        $.post(ajax, data, function (res) {
            if (res.success) {
                if (onSuccess) onSuccess(res.data);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : i18n.error;
                if (onError) onError(msg); else alert(msg);
            }
        }).fail(function () {
            var msg = i18n.error;
            if (onError) onError(msg); else alert(msg);
        });
    }

    function setStatus(text, ok) {
        var $s = $('#cpic-tpl-status');
        $s.text(text).css('color', ok ? '#28a745' : '#dc3545');
    }

    function exportTemplate(id) {
        var tpl = cpicData.templates.find(function (item) {
            return parseInt(item.id, 10) === parseInt(id, 10);
        });
        if (!tpl) return;

        var portable = {
            format: 'cpic-template',
            version: 1,
            name: tpl.name,
            slug: tpl.slug,
            config: tpl.config
        };
        var blob = new Blob([JSON.stringify(portable, null, 2)], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = (tpl.slug || 'cpic-template') + '.json';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function importTemplateFile(file) {
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function () {
            var parsed;
            try {
                parsed = JSON.parse(reader.result);
            } catch (e) {
                alert('The selected file is not valid JSON.');
                return;
            }
            if (!parsed || parsed.format !== 'cpic-template' || !parsed.config) {
                alert('This file is not a CPIC template export.');
                return;
            }
            cpicAjax('cpic_template_import', { template: JSON.stringify(parsed) }, function (data) {
                alert(data.message);
                window.location.reload();
            });
        };
        reader.readAsText(file);
    }

    /* ============================================================
       Element field specs
       ============================================================ */
    var ELEMENT_FIELDS = {
        featured_image: [
            { key: 'x', type: 'number', label: 'X' },
            { key: 'y', type: 'number', label: 'Y' },
            { key: 'width', type: 'number', label: 'Width' },
            { key: 'height', type: 'number', label: 'Height' },
            { key: 'fit', type: 'select', label: 'Fit', options: ['cover', 'contain', 'fill'] },
            { key: 'border_radius', type: 'number', label: 'Border Radius' },
            { key: 'opacity', type: 'number', label: 'Opacity (0-100)' },
            { key: 'fallback', type: 'select', label: 'No Image Fallback', options: [
                { v: 'hide', l: 'Hide element' },
                { v: 'template_image', l: 'Use template image' }
            ] },
            { key: 'fallback_image', type: 'url', label: 'Fallback Image URL' }
        ],
        headline: [
            { key: 'font', type: 'select', label: 'Font', options: fonts },
            { key: 'font_size', type: 'number', label: 'Font Size' },
            { key: 'min_font_size', type: 'number', label: 'Min Font Size' },
            { key: 'max_font_size', type: 'number', label: 'Max Font Size' },
            { key: 'color', type: 'color', label: 'Colour' },
            { key: 'align', type: 'select', label: 'Align', options: ['left', 'center', 'right'] },
            { key: 'max_lines', type: 'number', label: 'Max Lines' },
            { key: 'max_width', type: 'number', label: 'Max Width' },
            { key: 'max_height', type: 'number', label: 'Max Height (0 = available canvas height)' },
            { key: 'line_height', type: 'number', label: 'Line Height', step: '0.1' },
            { key: 'letter_spacing', type: 'number', label: 'Letter Spacing', step: '0.1' },
            { key: 'x', type: 'number', label: 'X' },
            { key: 'y', type: 'number', label: 'Y' },
            { key: 'shadow_color', type: 'text', label: 'Shadow Colour (hex or rgba)' },
            { key: 'shadow_offset', type: 'number', label: 'Shadow Offset' },
            { key: 'bold', type: 'checkbox', label: 'Bold' },
            { key: 'auto_scale', type: 'checkbox', label: 'Auto Scale to Fit' }
        ],
        category: [
            { key: 'font', type: 'select', label: 'Font', options: fonts },
            { key: 'font_size', type: 'number', label: 'Font Size' },
            { key: 'color', type: 'color', label: 'Colour' },
            { key: 'background_color', type: 'color', label: 'Background Colour' },
            { key: 'x', type: 'number', label: 'X' },
            { key: 'y', type: 'number', label: 'Y' },
            { key: 'padding_x', type: 'number', label: 'Padding X' },
            { key: 'padding_y', type: 'number', label: 'Padding Y' },
            { key: 'border_radius', type: 'number', label: 'Border Radius' },
            { key: 'bold', type: 'checkbox', label: 'Bold' }
        ],
        date: [
            { key: 'font', type: 'select', label: 'Font', options: fonts },
            { key: 'font_size', type: 'number', label: 'Font Size' },
            { key: 'color', type: 'color', label: 'Colour' },
            { key: 'background_color', type: 'color', label: 'Background Colour' },
            { key: 'format', type: 'select', label: 'Format', options: [
                { v: 'bangla', l: 'Bangla date' },
                { v: 'default', l: 'WordPress date' }
            ] },
            { key: 'x', type: 'number', label: 'X' },
            { key: 'y', type: 'number', label: 'Y' },
            { key: 'padding_x', type: 'number', label: 'Padding X' },
            { key: 'padding_y', type: 'number', label: 'Padding Y' },
            { key: 'border_radius', type: 'number', label: 'Border Radius' },
            { key: 'bold', type: 'checkbox', label: 'Bold' }
        ],
        logo: [
            { key: 'source', type: 'url', label: 'Logo URL' },
            { key: 'x', type: 'number', label: 'X' },
            { key: 'y', type: 'number', label: 'Y' },
            { key: 'width', type: 'number', label: 'Width' },
            { key: 'opacity', type: 'number', label: 'Opacity (0-100)' }
        ],
        author: [
            { key: 'font', type: 'select', label: 'Font', options: fonts },
            { key: 'font_size', type: 'number', label: 'Font Size' },
            { key: 'color', type: 'color', label: 'Colour' },
            { key: 'x', type: 'number', label: 'X' },
            { key: 'y', type: 'number', label: 'Y' }
        ],
        website_name: [
            { key: 'font', type: 'select', label: 'Font', options: fonts },
            { key: 'font_size', type: 'number', label: 'Font Size' },
            { key: 'color', type: 'color', label: 'Colour' },
            { key: 'x', type: 'number', label: 'X' },
            { key: 'y', type: 'number', label: 'Y' }
        ]
    };

    /* ============================================================
       Field builder
       ============================================================ */
    function buildField(field, value) {
        var v = (value === undefined || value === null) ? '' : value;
        var id = 'cpic-fld-' + Math.random().toString(36).substr(2, 6);

        var html = '<label>' + field.label + ' ';

        if (field.type === 'select') {
            html += '<select id="' + id + '" data-key="' + field.key + '">';
            (field.options || []).forEach(function (opt) {
                var ov = (typeof opt === 'object') ? opt.v : opt;
                var ol = (typeof opt === 'object') ? opt.l : opt;
                var sel = String(v) === String(ov) ? ' selected' : '';
                html += '<option value="' + ov + '"' + sel + '>' + ol + '</option>';
            });
            html += '</select>';
        } else if (field.type === 'checkbox') {
            html = '<label><input type="checkbox" id="' + id + '" data-key="' + field.key + '" value="1"' + (v ? ' checked' : '') + ' /> ' + field.label + '</label>';
        } else if (field.type === 'color') {
            html += '<input type="text" id="' + id + '" data-key="' + field.key + '" class="cpic-color-picker" value="' + (typeof v === 'string' ? v.replace(/"/g, '&quot;') : '') + '" />';
        } else if (field.type === 'url') {
            html += '<input type="text" id="' + id + '" data-key="' + field.key + '" value="' + (typeof v === 'string' ? v.replace(/"/g, '&quot;') : '') + '" />';
        } else {
            var step = field.step ? ' step="' + field.step + '"' : '';
            html += '<input type="number" id="' + id + '" data-key="' + field.key + '"' + step + ' value="' + v + '" />';
        }
        html += '</label>';
        return html;
    }

    function populateElementCard(key, elConfig) {
        var $card = $('.cpic-tpl-element[data-element="' + key + '"]');
        if (!$card.length) return;
        var $enabled = $card.find('.cpic-tpl-el-enabled');
        $enabled.prop('checked', !!(elConfig && elConfig.enabled));

        var $fields = $card.find('.cpic-tpl-el-fields');
        $fields.empty();
        var specs = ELEMENT_FIELDS[key] || [];
        specs.forEach(function (field) {
            var value = (elConfig && elConfig[field.key] !== undefined) ? elConfig[field.key] : defaultFor(field);
            $fields.append(buildField(field, value));
        });
    }

    function defaultFor(field) {
        switch (field.key) {
            case 'font': return 'Noto Serif Bengali';
            case 'font_size': return 28;
            case 'min_font_size': return 20;
            case 'max_font_size': return 56;
            case 'max_lines': return 3;
            case 'max_width': return 940;
            case 'max_height': return 0;
            case 'line_height': return 1.35;
            case 'letter_spacing': return 0;
            case 'padding_x': return 16;
            case 'padding_y': return 6;
            case 'border_radius': return 6;
            case 'opacity': return 100;
            case 'shadow_offset': return 2;
            case 'x': return 40;
            case 'y': return 40;
            case 'width': return 120;
            case 'height': return 0;
            case 'align': return 'left';
            case 'fit': return 'cover';
            case 'fallback': return 'hide';
            case 'format': return 'bangla';
            case 'color': return '#FFFFFF';
            case 'background_color': return '#E53935';
            case 'shadow_color': return 'rgba(0,0,0,0.65)';
            case 'bold': return 1;
            case 'auto_scale': return 1;
            default: return '';
        }
    }

    /* ============================================================
       Collect editor state → config
       ============================================================ */
    function collectConfig() {
        var config = {
            name: $('#cpic-tpl-name').val(),
            canvas: {
                width: parseInt($('#cpic-tpl-canvas-width').val(), 10) || 1080,
                height: parseInt($('#cpic-tpl-canvas-height').val(), 10) || 1080,
                background_type: $('#cpic-tpl-bg-type').val(),
                background_image: $('#cpic-tpl-bg-img').val(),
                background_color: $('#cpic-tpl-bg-color').val(),
                gradient_from: $('#cpic-tpl-bg-grad-from').val(),
                gradient_to: $('#cpic-tpl-bg-grad-to').val(),
                padding: 40
            },
            caption: {
                include_headline: $('#cpic-tpl-caption-headline').is(':checked') ? 1 : 0,
                include_first_paragraph: $('#cpic-tpl-caption-first-paragraph').is(':checked') ? 1 : 0
            },
            elements: {},
            shapes: []
        };

        $('.cpic-tpl-element').each(function () {
            var key = $(this).data('element');
            var $enabled = $(this).find('.cpic-tpl-el-enabled');
            var el = { enabled: $enabled.is(':checked') ? 1 : 0 };
            var type = (key === 'featured_image' || key === 'logo') ? 'image' : 'text';
            el.type = type;

            $(this).find('.cpic-tpl-el-fields [data-key]').each(function () {
                var $f = $(this);
                var k = $f.data('key');
                var type = $f.attr('type');
                if (type === 'checkbox') {
                    el[k] = $f.is(':checked') ? 1 : 0;
                } else if (type === 'number') {
                    var floatKeys = { line_height: 1, letter_spacing: 1 };
                    el[k] = floatKeys[k] ? parseFloat($f.val()) || 0 : parseInt($f.val(), 10) || 0;
                } else {
                    el[k] = $f.val();
                }
            });

            // Add sensible z-index ordering.
            var z = { featured_image: 10, logo: 5, headline: 30, category: 20, date: 25, author: 26, website_name: 27 };
            if (!el.z_index && z[key]) el.z_index = z[key];
            if (!el.bold && key === 'headline') el.bold = 1;

            config.elements[key] = el;
        });

        // Shapes.
        $('#cpic-tpl-shapes .cpic-tpl-shape').each(function () {
            var shape = {};
            $(this).find('[data-shape-key]').each(function () {
                var k = $(this).data('shapeKey');
                var type = $(this).attr('type');
                if (type === 'number') shape[k] = parseInt($(this).val(), 10) || 0;
                else shape[k] = $(this).val();
            });
            config.shapes.push(shape);
        });

        return config;
    }

    /* ============================================================
       Editor open/close
       ============================================================ */
    function openEditor(tpl) {
        currentTemplate = tpl || { id: 0, name: '', config: null };
        $('#cpic-tpl-id').val(currentTemplate.id || 0);
        $('#cpic-tpl-editor-title').text(currentTemplate.id ? 'Edit Template' : 'Add New Template');
        $('#cpic-tpl-name').val(currentTemplate.name || '');

        var cfg = currentTemplate.config || {};
        var canvas = cfg.canvas || {};
        var caption = cfg.caption || {};

        $('#cpic-tpl-canvas-width').val(canvas.width || 1080);
        $('#cpic-tpl-canvas-height').val(canvas.height || 1080);
        $('#cpic-tpl-bg-type').val(canvas.background_type || 'gradient').trigger('change');
        $('#cpic-tpl-bg-color').val(canvas.background_color || '#1a1a2e');
        $('#cpic-tpl-bg-grad-from').val(canvas.gradient_from || '#667eea');
        $('#cpic-tpl-bg-grad-to').val(canvas.gradient_to || '#764ba2');
        $('#cpic-tpl-bg-img').val(canvas.background_image || '');
        $('#cpic-tpl-caption-headline').prop('checked', !!caption.include_headline);
        $('#cpic-tpl-caption-first-paragraph').prop('checked', caption.include_first_paragraph === undefined ? true : !!caption.include_first_paragraph);

        var elements = cfg.elements || {};
        Object.keys(ELEMENT_FIELDS).forEach(function (key) {
            populateElementCard(key, elements[key]);
        });

        // Shapes.
        var $shapes = $('#cpic-tpl-shapes');
        $shapes.empty();
        (cfg.shapes || []).forEach(addShapeRow);

        // Re-bind color pickers.
        $('.cpic-color-picker').each(function () {
            if ($(this).data('wpColorPicker') || $.fn.wpColorPicker) {
                try {
                    $(this).wpColorPicker('destroy');
                } catch (e) {}
            }
        });
        if ($.fn.wpColorPicker) {
            $('.cpic-tpl-el-fields .cpic-color-picker, #cpic-tpl-bg-color, #cpic-tpl-bg-grad-from, #cpic-tpl-bg-grad-to').wpColorPicker();
        }

        toggleBgRows();

        $('#cpic-tpl-editor').show();
        $('#cpic-tpl-preview-img').hide();
        $('#cpic-tpl-preview-empty').show();
        window.scrollTo({ top: $('#cpic-tpl-editor').offset().top - 40, behavior: 'smooth' });
    }

    function closeEditor() {
        $('#cpic-tpl-editor').hide();
        currentTemplate = null;
        window.location.reload();
    }

    function toggleBgRows() {
        var type = $('#cpic-tpl-bg-type').val();
        $('#cpic-tpl-bg-color-row').toggle(type === 'color');
        $('#cpic-tpl-bg-grad-row').toggle(type === 'gradient');
        $('#cpic-tpl-bg-grad-row2').toggle(type === 'gradient');
        $('#cpic-tpl-bg-img-row').toggle(type === 'image');
    }

    /* ============================================================
       Shape rows
       ============================================================ */
    function addShapeRow(shape) {
        shape = shape || {};
        var html =
            '<div class="cpic-tpl-shape" style="border:1px solid #c3c4c7;padding:10px;margin-bottom:8px;border-radius:6px;">' +
            '<label>Type <select data-shape-key="type">' +
            '<option value="rect"' + (shape.type === 'rect' ? ' selected' : '') + '>Rectangle</option>' +
            '<option value="line"' + (shape.type === 'line' ? ' selected' : '') + '>Line</option>' +
            '<option value="ellipse"' + (shape.type === 'ellipse' ? ' selected' : '') + '>Ellipse</option>' +
            '</select></label>' +
            '<label>Colour <input type="text" data-shape-key="color" value="' + (shape.color || '#000000') + '" /></label>' +
            '<label>Opacity <input type="number" data-shape-key="opacity" min="0" max="100" value="' + (shape.opacity === undefined ? 100 : shape.opacity) + '" /></label>' +
            '<label>Z Index <input type="number" data-shape-key="z_index" value="' + (shape.z_index === undefined ? 0 : shape.z_index) + '" /></label>' +
            '<label>X <input type="number" data-shape-key="x" value="' + (shape.x === undefined ? 0 : shape.x) + '" /></label>' +
            '<label>Y <input type="number" data-shape-key="y" value="' + (shape.y === undefined ? 0 : shape.y) + '" /></label>' +
            '<label>Width <input type="number" data-shape-key="width" value="' + (shape.width === undefined ? 1080 : shape.width) + '" /></label>' +
            '<label>Height <input type="number" data-shape-key="height" value="' + (shape.height === undefined ? 80 : shape.height) + '" /></label>' +
            '<button type="button" class="button button-small cpic-tpl-shape-remove">Remove</button>' +
            '</div>';
        $('#cpic-tpl-shapes').append(html);
    }

    /* ============================================================
       Actions
       ============================================================ */
    function bindListActions() {
        $(document).on('click', '.cpic-tpl-edit', function () {
            var id = parseInt($(this).data('id'), 10);
            var tpl = cpicData.templates.find(function (t) { return parseInt(t.id, 10) === id; });
            if (tpl) openEditor(tpl);
        });

        $(document).on('click', '.cpic-tpl-duplicate', function () {
            var id = parseInt($(this).data('id'), 10);
            cpicAjax('cpic_template_duplicate', { id: id }, function (d) {
                alert(d.message);
                window.location.reload();
            });
        });

        $(document).on('click', '.cpic-tpl-export', function () {
            exportTemplate($(this).data('id'));
        });

        $(document).on('click', '.cpic-tpl-toggle', function () {
            var id = parseInt($(this).data('id'), 10);
            var active = parseInt($(this).data('active'), 10);
            cpicAjax('cpic_template_toggle', { id: id, active: active }, function (d) {
                window.location.reload();
            });
        });

        $(document).on('click', '.cpic-tpl-default', function () {
            var id = parseInt($(this).data('id'), 10);
            cpicAjax('cpic_template_set_default', { id: id }, function (d) {
                alert(d.message);
                window.location.reload();
            });
        });

        $(document).on('click', '.cpic-tpl-delete', function () {
            if (!confirm(i18n.confirmDelete)) return;
            var id = parseInt($(this).data('id'), 10);
            cpicAjax('cpic_template_delete', { id: id }, function (d) {
                alert(d.message);
                window.location.reload();
            });
        });

        $(document).on('click', '.cpic-tpl-preview', function () {
            var id = parseInt($(this).data('id'), 10);
            var tpl = cpicData.templates.find(function (t) { return parseInt(t.id, 10) === id; });
            if (!tpl) return;
            openEditor(tpl);
            previewTemplate();
        });
    }

    function previewTemplate() {
        var config = collectConfig();
        $('#cpic-tpl-preview-img').hide();
        $('#cpic-tpl-preview-empty').show().text('Rendering Bengali text in your browser…');
        cpicAjax('cpic_template_preview', { config: JSON.stringify(config) }, function (d) {
            window.cpicRenderTemplate(d.config, d.data).then(function (canvas) {
                $('#cpic-tpl-preview-img').attr('src', canvas.toDataURL('image/png')).show();
                $('#cpic-tpl-preview-empty').hide();
            }).catch(function (error) {
                $('#cpic-tpl-preview-empty').show().text('Preview error: ' + error.message);
            });
        }, function (msg) {
            $('#cpic-tpl-preview-empty').show().text('Preview error: ' + msg);
        });
    }

    function saveTemplate() {
        var config = collectConfig();
        var id = parseInt($('#cpic-tpl-id').val(), 10) || 0;
        cpicAjax('cpic_template_save', {
            id: id,
            name: $('#cpic-tpl-name').val(),
            config: JSON.stringify(config)
        }, function (d) {
            setStatus(d.message, true);
            $('#cpic-tpl-id').val(d.id);
            window.setTimeout(function () { window.location.reload(); }, 800);
        }, function (msg) {
            setStatus(msg, false);
        });
    }

    $(document).ready(function () {
        $('#cpic-tpl-add').on('click', function () {
            openEditor(null);
        });

        $('#cpic-tpl-import').on('click', function () {
            $('#cpic-tpl-import-file').val('').trigger('click');
        });
        $('#cpic-tpl-import-file').on('change', function () {
            importTemplateFile(this.files && this.files[0]);
        });

        $('#cpic-tpl-cancel').on('click', closeEditor);
        $('#cpic-tpl-save').on('click', saveTemplate);
        $('#cpic-tpl-preview-btn').on('click', previewTemplate);

        $('#cpic-tpl-bg-type').on('change', toggleBgRows);
        $('#cpic-tpl-shape-add').on('click', function () { addShapeRow(null); });
        $(document).on('click', '.cpic-tpl-shape-remove', function () { $(this).closest('.cpic-tpl-shape').remove(); });

        $(document).on('click', '.cpic-media-bg', function () {
            if (!wp || !wp.media) return;
            var frame = wp.media({ title: 'Select Image', multiple: false, library: { type: 'image' } });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $('#cpic-tpl-bg-img').val(att.url);
            });
            frame.open();
        });

        bindListActions();
    });

})(jQuery);
