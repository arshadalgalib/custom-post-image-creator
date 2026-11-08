/* Optional Skia smoke test for the browser Canvas renderer; not a WordPress integration test. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createCanvas, GlobalFonts, Image } = require('@napi-rs/canvas');
const repo = path.resolve(__dirname, '..');

for (const [file, family] of [
    ['NotoSerifBengali-Regular.ttf', 'Noto Serif Bengali'],
    ['NotoSansBengali-Regular.ttf', 'Noto Sans Bengali'],
    ['HindSiliguri-Regular.ttf', 'Hind Siliguri']
]) {
    assert.ok(GlobalFonts.registerFromPath(path.join(repo, 'fonts', file), family), `failed to register ${family}`);
}

// The web module uses browser FontFace; Skia already has the bundled files registered above.
global.window = global;
global.Image = Image;
global.document = { fonts: { add() {}, ready: Promise.resolve() } };
global.FontFace = class FontFaceMock {
    constructor(family) { this.family = family; }
    load() { return Promise.resolve(this); }
};
require(path.join(repo, 'js', 'canvas-renderer.js'));

const samples = [
    'বাংলাদেশের উন্নয়নে নতুন উদ্যোগ।',
    'প্রযুক্তির মাধ্যমে বদলে যাচ্ছে আমাদের জীবন।',
    'শিক্ষার্থীদের জন্য গুরুত্বপূর্ণ ঘোষণা।',
    'ক্ষুদ্র ব্যবসায়ীদের জন্য নতুন সুযোগ।',
    'জ্ঞানের আলো ছড়িয়ে পড়ুক সর্বত্র।',
    'বিশ্ববিদ্যালয়ে গবেষণার নতুন সম্ভাবনা।',
    'আগামী দিনের বাংলাদেশ গড়তে আমাদের সম্মিলিত প্রচেষ্টা।',
    'বাংলাদেশ — AI Research and Innovation.',
    'বাংলা: ০১২৩৪৫৬৭৮৯ / English: 0123456789'
];
const config = {
    canvas: { width: 1200, height: 1000, background_type: 'color', background_color: '#142033' },
    elements: {}
};
samples.forEach((text, i) => {
    config.elements['sample_' + i] = {
        type: 'text', enabled: 1, text, x: 60, y: 30 + i * 108,
        font: 'Noto Serif Bengali', font_size: 43, min_font_size: 28, max_font_size: 43,
        max_width: i === 6 ? 780 : 1080, max_lines: i === 6 ? 2 : 1,
        auto_scale: i === 6, color: '#ffffff', align: 'left'
    };
});
const canvas = createCanvas(1, 1);
CPICCanvasRenderer.render(canvas, config, {}, { pluginUrl: 'https://test.invalid/plugin/' }).then(() => {
    assert.equal(canvas.width, 1200);
    assert.equal(canvas.height, 1000);
    const image = canvas.toBuffer('image/png');
    assert.equal(image.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
    const pixels = canvas.getContext('2d').getImageData(45, 20, 1100, 900).data;
    let bright = 0;
    for (let i = 0; i < pixels.length; i += 4) if (pixels[i] > 200 && pixels[i + 1] > 200 && pixels[i + 2] > 200) bright++;
    assert.ok(bright > 2000, `expected visible white glyph pixels, found ${bright}`);
    const output = path.join(repo, 'test-artifacts', 'bengali-canvas-renderer.png');
    fs.mkdirSync(path.dirname(output), { recursive: true });
    fs.writeFileSync(output, image);
    console.log(`PASS: ${samples.length} Bengali/mixed-script cases; ${bright} bright text pixels; ${output}`);
}).catch((error) => { console.error(error); process.exitCode = 1; });
