/* Render Bengali with every requested bundled family through Skia Canvas. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createCanvas, GlobalFonts, Image } = require('@napi-rs/canvas');
const repo = path.resolve(__dirname, '..');
const fontDir = path.join(repo, 'fonts');
const adminSource = fs.readFileSync(path.join(repo, 'includes', 'class-admin-ui.php'), 'utf8');
assert.match(adminSource, /'SolaimanLipi'\s*=>\s*array\(\s*'SolaimanLipi\.ttf',\s*'SolaimanLipi-Bold\.ttf'/,
    'Template Manager must expose SolaimanLipi only when both regular and bold files are present');
const families = [
    { name: 'SolaimanLipi', regular: 'SolaimanLipi.ttf', bold: 'SolaimanLipi-Bold.ttf' },
    { name: 'Noto Serif Bengali', regular: 'NotoSerifBengali-Regular.ttf' },
    { name: 'Hind Siliguri', regular: 'HindSiliguri-Regular.ttf' }
];

for (const item of families) {
    assert.ok(fs.statSync(path.join(fontDir, item.regular)).size > 10000, `${item.name} regular font is present`);
    assert.ok(GlobalFonts.registerFromPath(path.join(fontDir, item.regular), item.name), `${item.name} regular font registers`);
    if (item.bold) {
        assert.ok(fs.statSync(path.join(fontDir, item.bold)).size > 10000, `${item.name} bold font is present`);
        assert.ok(GlobalFonts.registerFromPath(path.join(fontDir, item.bold), item.name), `${item.name} bold font registers`);
    }
}

const loadedFaces = [];
global.window = global;
global.Image = Image;
global.document = { fonts: { add(face) { loadedFaces.push({ family: face.family, weight: face.weight }); }, ready: Promise.resolve() } };
global.FontFace = class FontFaceMock {
    constructor(family, source, descriptors) {
        this.family = family;
        this.source = source;
        this.weight = descriptors && descriptors.weight || '400';
    }
    load() {
        assert.match(this.source, /\/fonts\//);
        assert.ok(fs.existsSync(path.join(fontDir, this.source.split('/fonts/').pop().replace(/[)'"].*$/, ''))), `font URL exists for ${this.family}`);
        return Promise.resolve(this);
    }
};
require(path.join(repo, 'js', 'canvas-renderer.js'));

const text = 'ক্ষুদ্র শিক্ষার্থীদের বিশ্ববিদ্যালয়ে গবেষণার নতুন সম্ভাবনা।';
const config = { canvas: { width: 1200, height: 840, background_type: 'color', background_color: '#152238' }, elements: {} };
const widths = {};
families.forEach((item, index) => {
    config.elements['font_' + index] = {
        type: 'text', enabled: 1, text, font: item.name, font_size: 54,
        min_font_size: 36, max_font_size: 54, max_width: 1120, max_lines: 2,
        x: 40, y: 25 + index * 175, bold: false, color: '#ffffff', align: 'left'
    };
    const measure = createCanvas(1, 1).getContext('2d');
    measure.font = '40px "' + item.name + '"';
    widths[item.name] = measure.measureText(text).width;
    config.elements['bold_' + index] = {
        type: 'text', enabled: 1, text: 'বাংলা যুক্তাক্ষর: ক্ষ, জ্ঞ, শ্র, ত্র', font: item.name,
        font_size: 36, min_font_size: 30, max_font_size: 36, max_width: 1120, max_lines: 1,
        x: 40, y: 520 + index * 100, bold: true, color: '#ffe08a', align: 'left'
    };
});

const canvas = createCanvas(1, 1);
CPICCanvasRenderer.render(canvas, config, {}, { pluginUrl: 'https://test.invalid/plugin/' }).then(async () => {
    assert.equal(canvas.width, 1200);
    assert.equal(canvas.height, 840);
    const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    for (let region = 0; region < 3; region++) {
        let visible = 0;
        const top = 20 + region * 175;
        for (let y = top; y < top + 145; y++) {
            for (let x = 20; x < 1160; x++) {
                const i = (y * canvas.width + x) * 4;
                if (data[i] > 210 && data[i + 1] > 210 && data[i + 2] > 210) visible++;
            }
        }
        assert.ok(visible > 2000, `${families[region].name} produces visible Bengali glyph pixels (${visible})`);
        let boldVisible = 0;
        const boldTop = 515 + region * 100;
        for (let y = boldTop; y < boldTop + 75; y++) {
            for (let x = 20; x < 1160; x++) {
                const i = (y * canvas.width + x) * 4;
                if (data[i] > 220 && data[i + 1] > 160 && data[i + 2] < 190) boldVisible++;
            }
        }
        assert.ok(boldVisible > 500, `${families[region].name} bold setting renders visible Bengali glyphs (${boldVisible})`);
    }
    assert.ok(loadedFaces.some((face) => face.family === 'SolaimanLipi' && face.weight === '400'), 'SolaimanLipi regular face loads');
    assert.ok(loadedFaces.some((face) => face.family === 'SolaimanLipi' && face.weight === '700'), 'SolaimanLipi bold face loads');
    assert.ok(loadedFaces.some((face) => face.family === 'Noto Serif Bengali' && face.weight === '400'), 'Noto Serif Bengali face loads');
    assert.ok(loadedFaces.some((face) => face.family === 'Hind Siliguri' && face.weight === '400'), 'Hind Siliguri face loads');
    const distinctWidths = new Set(Object.values(widths).map((width) => Math.round(width * 10) / 10));
    assert.equal(distinctWidths.size, 3, 'each requested font family must provide its own text metrics, not fall back to another face');
    const evidenceDir = path.join(repo, 'test-artifacts');
    fs.mkdirSync(evidenceDir, { recursive: true });
    fs.writeFileSync(path.join(evidenceDir, 'bengali-font-families.png'), canvas.toBuffer('image/png'));
    await CPICCanvasRenderer.ensureFonts('https://test.invalid/plugin/', ['Solaimani Lipi']);
    assert.ok(loadedFaces.some((face) => face.family === 'SolaimanLipi'), 'the common Solaimani Lipi spelling maps to the bundled family');
    console.log('PASS: SolaimanLipi regular/bold, Noto Serif Bengali, and Hind Siliguri render Bengali with distinct font faces.');
}).catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
