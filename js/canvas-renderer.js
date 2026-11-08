/* global window, document, FontFace */
(function (root) {
    'use strict';

    var FONT_FILES = {
        'Noto Serif Bengali': 'NotoSerifBengali-Regular.ttf',
        'Noto Sans Bengali': 'NotoSansBengali-Regular.ttf',
        'Hind Siliguri': 'HindSiliguri-Regular.ttf',
        'SolaimanLipi': 'SolaimanLipi.ttf',
        'Siyam Rupali': 'SiyamRupali.ttf'
    };

    function number(value, fallback) {
        var n = Number(value);
        return Number.isFinite(n) ? n : fallback;
    }

    function color(value, fallback) {
        var candidate = String(value || '').trim();
        if (/^#[0-9a-f]{3,8}$/i.test(candidate)) return candidate;
        if (/^rgba?\(\s*(?:\d+(?:\.\d+)?\s*,\s*){2}\d+(?:\.\d+)?(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i.test(candidate)) return candidate;
        return fallback;
    }

    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            if (!url) { resolve(null); return; }
            var image = new root.Image();
            image.crossOrigin = 'anonymous';
            image.onload = function () { resolve(image); };
            image.onerror = function () { reject(new Error('Could not load template image: ' + url)); };
            image.src = url;
        });
    }

    function fontFamily(name) {
        if (!name) return 'Noto Serif Bengali';
        if (FONT_FILES[name]) return name;
        throw new Error('Unsupported font family: ' + name + '. Choose one of the fonts listed in Template Manager and ensure its font file is installed.');
    }

    var loadedFonts = {};
    function ensureFonts(baseUrl, choices) {
        if (typeof root.FontFace !== 'function' || !root.document.fonts) throw new Error('This browser does not support FontFace; use a current Chrome, Edge, Firefox, or Safari browser.');
        var wanted = choices || Object.keys(FONT_FILES);
        return Promise.all(wanted.map(function (name) {
            name = fontFamily(name);
            if (loadedFonts[name]) return loadedFonts[name];
            var file = FONT_FILES[name];
            var face = new root.FontFace(name, 'url(' + baseUrl + 'fonts/' + file + ')');
            loadedFonts[name] = face.load().then(function (loaded) {
                root.document.fonts.add(loaded);
                return loaded;
            }).catch(function (error) {
                delete loadedFonts[name];
                throw new Error('Could not load font "' + name + '" from this site. Check the plugin font file and browser CORS policy. ' + error.message);
            });
            return loadedFonts[name];
        }));
    }

    function elementText(key, element, data) {
        var fields = {
            headline: 'title', category: 'category', date: 'date', author: 'author', website_name: 'website'
        };
        var value = fields[key] ? data[fields[key]] : element.text;
        return String(value == null ? '' : value).trim();
    }

    function paragraphs(text) {
        return String(text).replace(/\r\n?/g, '\n').split('\n');
    }

    function splitLongWord(ctx, word, maxWidth) {
        if (ctx.measureText(word).width <= maxWidth || !root.Intl || !root.Intl.Segmenter) return [word];
        var segments = Array.from(new root.Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(word), function (part) { return part.segment; });
        var pieces = [], current = '';
        segments.forEach(function (cluster) {
            if (current && ctx.measureText(current + cluster).width > maxWidth) { pieces.push(current); current = ''; }
            current += cluster;
        });
        if (current) pieces.push(current);
        return pieces;
    }

    function wrapText(ctx, text, maxWidth) {
        var result = [];
        paragraphs(text).forEach(function (paragraph) {
            var words = paragraph.trim().split(/\s+/).filter(Boolean);
            if (!words.length) { result.push(''); return; }
            var line = '';
            words.forEach(function (word) {
                splitLongWord(ctx, word, maxWidth).forEach(function (part) {
                    var candidate = line ? line + ' ' + part : part;
                    if (!line || ctx.measureText(candidate).width <= maxWidth) line = candidate;
                    else { result.push(line); line = part; }
                });
            });
            if (line) result.push(line);
        });
        return result;
    }

    function roundedRect(ctx, x, y, width, height, radius) {
        radius = Math.max(0, Math.min(radius, width / 2, height / 2));
        if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x, y, width, height, radius); return; }
        ctx.beginPath(); ctx.moveTo(x + radius, y); ctx.lineTo(x + width - radius, y); ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
        ctx.lineTo(x + width, y + height - radius); ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
        ctx.lineTo(x + radius, y + height); ctx.quadraticCurveTo(x, y + height, x, y + height - radius); ctx.lineTo(x, y + radius); ctx.quadraticCurveTo(x, y, x + radius, y); ctx.closePath();
    }

    function fitText(ctx, text, el, family) {
        var initial = Math.max(1, number(el.font_size, 28));
        var min = Math.max(1, number(el.min_font_size, initial));
        var max = Math.max(min, number(el.max_font_size, initial));
        var width = Math.max(1, number(el.max_width, 1000));
        var configuredLines = number(el.max_lines, 0);
        var maxLines = configuredLines > 0 ? configuredLines : 30;
        var maxHeight = number(el.max_height, 0);
        var lineFactor = Math.max(0.8, Math.min(3, number(el.line_height, 1.3)));
        var size = Math.min(initial, max);
        if (el.auto_scale) {
            var foundFit = false;
            for (var candidate = max; candidate >= min; candidate -= 1) {
                ctx.font = (el.bold ? '700 ' : '400 ') + candidate + 'px "' + family + '"';
                var candidateLines = wrapText(ctx, text, width);
                var heightFits = maxHeight <= 0 || candidateLines.length * candidate * lineFactor <= maxHeight;
                if (candidateLines.length <= maxLines && heightFits) { size = candidate; foundFit = true; break; }
            }
            if (!foundFit) size = min;
        }
        ctx.font = (el.bold ? '700 ' : '400 ') + size + 'px "' + family + '"';
        return { size: size, lines: wrapText(ctx, text, width) };
    }

    function drawImage(ctx, image, el, canvasWidth, canvasHeight) {
        if (!image) return;
        var x = number(el.x, 0), y = number(el.y, 0);
        var bw = Math.max(1, number(el.width, canvasWidth));
        var bh = Math.max(1, number(el.height, 0));
        if (!bh) bh = bw * image.height / image.width;
        var scale = el.fit === 'fill' ? null : (el.fit === 'contain' ? Math.min(bw / image.width, bh / image.height) : Math.max(bw / image.width, bh / image.height));
        var dw = scale === null ? bw : image.width * scale;
        var dh = scale === null ? bh : image.height * scale;
        var ox = (bw - dw) / 2, oy = (bh - dh) / 2;
        var alpha = number(el.opacity, 100);
        ctx.save();
        ctx.globalAlpha *= Math.max(0, Math.min(100, alpha)) / 100;
        if (number(el.border_radius, 0) > 0) {
            var r = Math.min(number(el.border_radius, 0), bw / 2, bh / 2);
            roundedRect(ctx, x, y, bw, bh, r); ctx.clip();
        }
        if (el.fit === 'contain') ctx.drawImage(image, x + ox, y + oy, dw, dh);
        else if (el.fit === 'fill') ctx.drawImage(image, x, y, bw, bh);
        else {
            ctx.beginPath(); ctx.rect(x, y, bw, bh); ctx.clip();
            ctx.drawImage(image, x + ox, y + oy, dw, dh);
        }
        ctx.restore();
    }

    function drawShape(ctx, shape, w, h) {
        var opacity = Math.max(0, Math.min(100, number(shape.opacity, 100))) / 100;
        ctx.save(); ctx.globalAlpha *= opacity; ctx.fillStyle = color(shape.color, '#000000'); ctx.strokeStyle = ctx.fillStyle;
        if (shape.type === 'line') {
            ctx.lineWidth = Math.max(1, number(shape.width, 2)); ctx.beginPath();
            ctx.moveTo(number(shape.x1, 0), number(shape.y1, 0)); ctx.lineTo(number(shape.x2, w), number(shape.y2, h)); ctx.stroke();
        } else if (shape.type === 'ellipse') {
            ctx.beginPath(); ctx.ellipse(number(shape.cx, w / 2), number(shape.cy, h / 2), Math.max(1, number(shape.rx, 50)), Math.max(1, number(shape.ry, 50)), 0, 0, Math.PI * 2); ctx.fill();
        } else {
            ctx.fillRect(number(shape.x, 0), number(shape.y, 0), number(shape.width, w), number(shape.height, h));
        }
        ctx.restore();
    }

    function drawText(ctx, el, text, w, h) {
        if (!text) return;
        ctx.save();
        if ('letterSpacing' in ctx) ctx.letterSpacing = number(el.letter_spacing, 0) + 'px';
        var family = fontFamily(el.font);
        var x = Math.max(0, Math.min(w - 1, number(el.x, 40)));
        var y = Math.max(0, Math.min(h - 1, number(el.y, 40)));
        var maxWidth = Math.max(1, Math.min(number(el.max_width, w - x), w - x));
        var configuredHeight = number(el.max_height, 0);
        var maxHeight = configuredHeight > 0 ? Math.min(configuredHeight, h - y) : h - y;
        var layout = fitText(ctx, text, Object.assign({}, el, { max_width: maxWidth, max_height: maxHeight }), family);
        var lineHeight = Math.max(0.8, Math.min(3, number(el.line_height, 1.3))) * layout.size;
        var configuredLines = number(el.max_lines, 0);
        var lineLimit = configuredLines > 0 ? configuredLines : layout.lines.length;
        var limit = Math.min(lineLimit, Math.max(1, Math.floor(maxHeight / lineHeight)));
        var lines = layout.lines.slice(0, limit);
        if (layout.lines.length > limit && lines.length) lines[lines.length - 1] = lines[lines.length - 1].replace(/\s+$/, '') + '…';
        ctx.font = (el.bold ? '700 ' : '400 ') + layout.size + 'px "' + family + '"';
        ctx.textBaseline = 'top'; ctx.textAlign = 'left';
        var widths = lines.map(function (line) { return ctx.measureText(line).width; });
        var boxWidth = Math.max.apply(Math, widths.concat([0]));
        var padX = number(el.padding_x, 0), padY = number(el.padding_y, 0);
        var align = el.align || 'left';
        if (el.background_color) {
            ctx.fillStyle = color(el.background_color, '#000000');
            var bx = x - padX;
            var bw = Math.min(maxWidth + padX * 2, boxWidth + padX * 2);
            var bh = lines.length * lineHeight + padY * 2;
            var radius = Math.max(0, number(el.border_radius, 0));
            roundedRect(ctx, bx, y - padY, bw, bh, radius); ctx.fill();
        }
        ctx.fillStyle = color(el.color, '#FFFFFF');
        var shadowOffset = Math.max(0, number(el.shadow_offset, 0));
        ctx.shadowColor = shadowOffset ? color(el.shadow_color, '#000000') : 'transparent';
        ctx.shadowBlur = shadowOffset ? Math.max(1, shadowOffset / 2) : 0;
        ctx.shadowOffsetX = shadowOffset;
        ctx.shadowOffsetY = shadowOffset;
        lines.forEach(function (line, i) {
            var lineWidth = widths[i];
            var offset = align === 'center' ? (maxWidth - lineWidth) / 2 : (align === 'right' ? maxWidth - lineWidth : 0);
            ctx.fillText(line, x + Math.max(0, offset), y + i * lineHeight, maxWidth);
        });
        ctx.restore();
    }

    function render(canvas, config, data, options) {
        options = options || {};
        if (!canvas || !canvas.getContext) return Promise.reject(new Error('Canvas element is unavailable.'));
        var cfg = config || {};
        var canvasCfg = cfg.canvas || {};
        var w = Math.max(64, Math.min(4096, number(canvasCfg.width, 1080)));
        var h = Math.max(64, Math.min(4096, number(canvasCfg.height, 1080)));
        var values = data || {};
        canvas.width = w; canvas.height = h;
        var ctx = canvas.getContext('2d');
        if (!ctx) return Promise.reject(new Error('Canvas 2D context is unavailable.'));
        var fonts = ['Noto Serif Bengali'];
        var images = [];
        var background = canvasCfg.background_type || 'gradient';
        var backgroundPromise = background === 'image' && canvasCfg.background_image ? loadImage(canvasCfg.background_image).then(function (img) { images.push({ background: true, image: img }); }) : Promise.resolve();
        var elements = cfg.elements || {};
        var jobs = [];
        Object.keys(elements).forEach(function (key) {
            var el = elements[key];
            if (!el || !el.enabled || el.type !== 'image') return;
            var url = key === 'featured_image' ? values.featured_image : (key === 'logo' ? el.source : '');
            if (key === 'featured_image' && !url && el.fallback === 'template_image') url = el.fallback_image;
            if (url) jobs.push(loadImage(url).then(function (img) { images.push({ key: key, image: img }); }));
        });
        Object.keys(elements).forEach(function (key) { if (elements[key] && elements[key].font) fonts.push(elements[key].font); });
        var fontPromise;
        try { fontPromise = ensureFonts(options.pluginUrl || '', fonts); }
        catch (fontError) { return Promise.reject(fontError); }
        return Promise.all([fontPromise, backgroundPromise].concat(jobs)).then(function () {
            return root.document.fonts.ready;
        }).then(function () {
            ctx.clearRect(0, 0, w, h);
            if (images.some(function (item) { return item.background; })) ctx.drawImage(images.filter(function (item) { return item.background; })[0].image, 0, 0, w, h);
            else if (background === 'color') { ctx.fillStyle = color(canvasCfg.background_color, '#1a1a2e'); ctx.fillRect(0, 0, w, h); }
            else {
                var gradient = ctx.createLinearGradient(0, 0, w, h);
                gradient.addColorStop(0, color(canvasCfg.gradient_from, '#667eea'));
                gradient.addColorStop(1, color(canvasCfg.gradient_to, '#764ba2'));
                ctx.fillStyle = gradient; ctx.fillRect(0, 0, w, h);
            }
            var layers = [];
            (cfg.shapes || []).forEach(function (shape) { layers.push({ type: 'shape', z: number(shape.z_index, 0), config: shape }); });
            Object.keys(elements).forEach(function (key) {
                var el = elements[key];
                if (!el || !el.enabled) return;
                var z = number(el.z_index, 0);
                if (el.type === 'image') {
                    var loaded = images.find(function (item) { return item.key === key; });
                    if (loaded) layers.push({ type: 'image', z: z, config: el, image: loaded.image });
                } else if (el.type === 'text') {
                    var text = elementText(key, el, values);
                    if (text) layers.push({ type: 'text', z: z, config: el, text: text });
                }
            });
            layers.sort(function (a, b) { return a.z - b.z; });
            layers.forEach(function (layer) {
                if (layer.type === 'shape') drawShape(ctx, layer.config, w, h);
                else if (layer.type === 'image') drawImage(ctx, layer.image, layer.config, w, h);
                else drawText(ctx, layer.config, layer.text, w, h);
            });
            return { canvas: canvas, width: w, height: h, renderer: 'cpic-canvas-1', templateHash: options.templateHash || '', inputHash: options.inputHash || '' };
        });
    }

    root.CPICCanvasRenderer = { render: render, ensureFonts: ensureFonts, fontFiles: FONT_FILES };
})(window);
