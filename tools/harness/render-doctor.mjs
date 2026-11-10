/**
 * Render gate: paints every bundled starter and the default template through
 * the studio's real painter, then asserts on what the painter reports and on
 * the pixels it produced.
 *
 * The point is to replace "it looks wrong" with measurements: overlapping
 * type, blocks that overflow their box or leave the canvas, gradients that
 * painted nothing, bold that did not change the glyphs, and backgrounds that
 * export transparent.
 *
 * Usage: node tools/harness/render-doctor.mjs [repoRoot]
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createCanvas, GlobalFonts, loadImage } from '@napi-rs/canvas';
import { JSDOM } from 'jsdom';
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const repoRoot = (process.argv[2] || join(here, '..', '..')).replace(/\/+$/, '');
const outDir = join(here, 'out');
mkdirSync(outDir, { recursive: true });

/* ── Fonts: exactly the faces the plugin ships ──────────────────── */

const FONT_FILES = [
	'NotoSansBengali-Regular.ttf',
	'NotoSerifBengali-Regular.ttf',
	'HindSiliguri-Regular.ttf',
	'HindSiliguri-SemiBold.ttf',
];

FONT_FILES.forEach((file) => {
	try {
		GlobalFonts.registerFromPath(join(repoRoot, 'fonts', file));
	} catch (error) {
		console.warn(`could not register ${file}:`, error.message);
	}
});

/* ── Plans for every starter, in two formats ────────────────────── */

const session = await createPluginSession(repoRoot);
const [exitCode, output] = await session.run(`require '${VM_ROOT}/tests/php/bootstrap.php';
$plugin = cpic_test_boot();
$plugin->service( 'migrator' )->install();

$post_id = 901;
CPIC_Test_State::$posts[ $post_id ] = cpic_test_make_post(
	array( 'ID' => $post_id, 'post_title' => 'বাংলাদেশের অর্থনীতি ঘুরে দাঁড়াচ্ছে' )
);

$templates = $plugin->service( 'template_manager' );
$builder   = $plugin->service( 'render_plan' );
$sources   = array();

$default = $templates->default_template();
if ( $default && isset( $default['document'] ) ) {
	$sources['default'] = $default['document'];
}

foreach ( $plugin->service( 'starter_library' )->designs() as $key => $design ) {
	if ( isset( $design['document'] ) && is_array( $design['document'] ) ) {
		$sources[ 'starter-' . $key ] = $design['document'];
	}
}

$plans = array();
foreach ( $sources as $key => $document ) {
	foreach ( array( 'square', 'story' ) as $format ) {
		$plan = $builder->build(
			array( 'document' => $document, 'format' => $format, 'post_id' => $post_id )
		);
		if ( ! is_wp_error( $plan ) ) {
			$plans[ $key . '-' . $format ] = array( 'plan' => $plan, 'document' => $document );
		}
	}
}

echo 'S' . base64_encode( wp_json_encode( $plans ) ) . 'E';
`);

const match = output.match(/S([A-Za-z0-9+/=\s]+)E/);
if (exitCode !== 0 || !match) {
	console.error(output);
	process.exit(1);
}

const plans = JSON.parse(Buffer.from(match[1].replace(/\s+/g, ''), 'base64').toString('utf8'));

/* ── Run the studio painter on a real canvas ────────────────────── */

const studioJs = readFileSync(join(repoRoot, 'resources/js/studio.js'), 'utf8');
const globals = { canvases: new Map() };

const dom = new JSDOM('<!doctype html><html><body></body></html>', {
	runScripts: 'outside-only',
	pretendToBeVisual: true,
	url: 'https://example.test/',
});
const { window } = dom;

window.HTMLCanvasElement.prototype.getContext = function (type) {
	if (type !== '2d') {
		return null;
	}

	const width = Math.max(1, Math.round(this.width || 1));
	const height = Math.max(1, Math.round(this.height || 1));
	const key = `${width}x${height}`;
	let entry = globals.canvases.get(this);

	if (!entry || entry.key !== key) {
		const canvas = createCanvas(width, height);
		const ctx = canvas.getContext('2d');
		const nativeDraw = ctx.drawImage.bind(ctx);

		// jsdom has no image decoder, so `Image` instances are harness-owned
		// wrappers; unwrap them to the decoder image the canvas can consume.
		ctx.drawImage = function (source, ...rest) {
			return nativeDraw(source && source.__nativeImage ? source.__nativeImage : source, ...rest);
		};

		entry = { key, canvas, ctx };
		globals.canvases.set(this, entry);
	}

	return entry.ctx;
};
window.HTMLCanvasElement.prototype.toDataURL = function () {
	const entry = globals.canvases.get(this);
	return entry ? entry.canvas.toDataURL('image/png') : 'data:,';
};
window.Element.prototype.getBoundingClientRect = () => ({ left: 0, top: 0, right: 800, bottom: 600, width: 800, height: 600, x: 0, y: 0 });
window.document.fonts = { load: () => Promise.resolve([]), ready: Promise.resolve() };

// The font manifest the editor would receive from the plugin.
const fontRows = FONT_FILES.map((file) => {
	const family = file.startsWith('Noto Sans')
		? 'Noto Sans Bengali'
		: file.startsWith('Noto Serif')
			? 'Noto Serif Bengali'
			: 'Hind Siliguri';

	return { family, weight: /SemiBold/.test(file) ? 600 : 400 };
});

window.CPIC = {
	restPath: '/cpic/v1/',
	restRoot: 'https://example.test/wp-json/',
	nonce: 'n',
	formats: {},
	strings: {},
	fonts: fontRows,
	can: {},
};
window.fetch = async () => ({ ok: false, status: 500, json: async () => ({}) });

// A real photograph stand-in: every image layer gets this, so an exported
// placeholder is a genuine fault rather than a missing fixture.
const sampleCanvas = createCanvas(640, 480);
const sampleCtx = sampleCanvas.getContext('2d');
const wash = sampleCtx.createLinearGradient(0, 0, 640, 480);
wash.addColorStop(0, '#1E3A8A');
wash.addColorStop(0.5, '#0E7490');
wash.addColorStop(1, '#155E75');
sampleCtx.fillStyle = wash;
sampleCtx.fillRect(0, 0, 640, 480);
sampleCtx.fillStyle = 'rgba(255, 255, 255, 0.16)';
for (let i = -480; i < 640; i += 48) {
	sampleCtx.fillRect(i, 0, 18, 480);
}
const sampleDataUrl = sampleCanvas.toDataURL('image/png');

Object.values(plans).forEach((entry) => {
	(entry.plan.layers || []).forEach((layer) => {
		if (layer.image && typeof layer.image === 'object') {
			layer.image.url = sampleDataUrl;
		}
	});
});

// jsdom has no image decoder; hand the painter the real one.
window.Image = class {
	constructor() {
		this.crossOrigin = '';
		this.naturalWidth = 0;
		this.width = 0;
		this.height = 0;
	}

	set src(value) {
		loadImage(value)
			.then((image) => {
				this.__nativeImage = image;
				this.naturalWidth = image.width;
				this.naturalHeight = image.height;
				this.width = image.width;
				this.height = image.height;
				this.complete = true;
				if (typeof this.onload === 'function') {
					this.onload();
				}
			})
			.catch(() => {
				if (typeof this.onerror === 'function') {
					this.onerror();
				}
			});
	}
};

window.eval(studioJs + '\n//# sourceURL=studio.js');
const studio = window.CPICStudio;

/* ── Assertions ─────────────────────────────────────────────────── */

const findings = [];
const rows = [];

const fail = (plan, id, kind, detail) => findings.push({ plan, id, kind, detail });

for (const [name, entry] of Object.entries(plans)) {
	const plan = entry.plan;
	const width = Number(plan.width) || 1080;
	const height = Number(plan.height) || 1080;

	const previous = window.fetch;
	window.fetch = async () => ({ ok: true, status: 200, json: async () => ({ ok: true, data: plan }) });

	let dataUrl = '';
	try {
		dataUrl = await studio.export(entry.document, { format: plan.format });
	} finally {
		window.fetch = previous;
	}

	if (!dataUrl.startsWith('data:image/png')) {
		fail(name, '-', 'export-failed', dataUrl || 'no data URL');
		continue;
	}

	const buffer = Buffer.from(dataUrl.split(',')[1] || '', 'base64');
	const canvasEntry = [...globals.canvases.values()].filter((candidate) => candidate.key === `${width}x${height}`).pop();

	if (!canvasEntry) {
		fail(name, '-', 'no-canvas', `expected ${width}x${height}`);
		continue;
	}

	writeFileSync(join(outDir, `doctor-${name}.png`), buffer);

	const report = studio.lastReport;
	const data = canvasEntry.ctx.getImageData(0, 0, canvasEntry.canvas.width, canvasEntry.canvas.height).data;
	const step = 4 * 97;
	const colours = new Set();
	let sampled = 0;
	let opaque = 0;
	let transparent = 0;

	for (let index = 3; index < data.length; index += step) {
		sampled++;
		if (data[index] > 250) {
			opaque++;
		} else if (data[index] < 250) {
			transparent++;
		}
		colours.add(`${data[index - 3]},${data[index - 2]},${data[index - 1]}`);
	}

	// A design must never export with transparent holes.
	if (transparent > sampled * 0.01) {
		fail(name, '-', 'transparent-export', `${transparent}/${sampled} sampled pixels not opaque`);
	}

	// A flat background means the background type did not paint.
	if (colours.size < 2) {
		fail(name, '-', 'flat-background', `only ${colours.size} distinct colour(s)`);
	}

	// Nothing painted at all is the headline "rendering is broken" symptom.
	const drawn = report.layers.filter((layer) => layer.drawn).length;
	if (drawn === 0) {
		fail(name, '-', 'nothing-drawn', 'no layer reported as drawn');
	}

	// Editor chrome must never reach an export: a dashed placeholder outline in
	// a published PNG looks like a broken design.
	report.layers.forEach((layer) => {
		if (layer.placeholder && layer.chrome) {
			fail(name, layer.id, 'chrome-exported', 'dashed placeholder outline drawn into the PNG');
		}
	});

	// A fallback where an image belongs is a real content problem, and the
	// editor warns about it, but the export itself must still be reported.
	if (report.headless !== true) {
		fail(name, '-', 'not-headless', 'export painted in interactive mode');
	}

	report.layers.forEach((layer) => {
		if (layer.placeholder) {
			fail(name, layer.id, 'missing-image', 'exported a placeholder instead of an image');
		}
	});

	const textRows = report.layers.filter((layer) => layer.type === 'text' && layer.drawn);

	textRows.forEach((layer) => {
		// A block running past its own box is clipped in the export.
		if (layer.overflowY > 2) {
			fail(name, layer.id, 'overflows-box', `${Math.round(layer.overflowY)}px below its box`);
		}

		// Type that leaves the canvas is lost silently.
		if (layer.bottom > height + 2) {
			fail(name, layer.id, 'leaves-canvas', `bottom ${Math.round(layer.bottom)} > ${height}`);
		}

		// A line wider than its box spills across the design.
		if (layer.widest > layer.textBoxWidth + 2) {
			fail(name, layer.id, 'line-too-wide', `${Math.round(layer.widest)}px in ${Math.round(layer.textBoxWidth)}px`);
		}

		// Shrinking to the floor means the design is wrong, not the type.
		if (layer.shrunk && layer.fontSize < 12) {
			fail(name, layer.id, 'type-too-small', `${layer.fontSize}px (asked for ${layer.requestedSize}px)`);
		}
	});

	// Contrast: type that is the same tone as what sits behind it is invisible.
	textRows.forEach((layer) => {
		const x0 = Math.max(0, Math.round(layer.x));
		const y0 = Math.max(0, Math.round(layer.y));
		const w = Math.min(canvasEntry.canvas.width - x0, Math.round(layer.width));
		const h = Math.min(canvasEntry.canvas.height - y0, Math.round(layer.bottom - layer.y));

		if (w < 4 || h < 4) {
			return;
		}

		const region = canvasEntry.ctx.getImageData(x0, y0, w, h).data;
		const histogram = new Map();
		let total = 0;

		for (let index = 0; index < region.length; index += 4) {
			const key = `${region[index]},${region[index + 1]},${region[index + 2]}`;
			histogram.set(key, (histogram.get(key) || 0) + 1);
			total++;
		}

		let modal = '';
		let modalCount = 0;
		histogram.forEach((count, key) => {
			if (count > modalCount) {
				modal = key;
				modalCount = count;
			}
		});

		const [br, bg, bb] = modal.split(',').map(Number);
		let inkCount = 0;
		let inkR = 0;
		let inkG = 0;
		let inkB = 0;

		// Glyph edges are blends of ink and background; sample only pixels that
		// are clearly one or the other, otherwise the "ink" colour is a smear
		// that makes every design look low contrast.
		for (let index = 0; index < region.length; index += 4) {
			const distance = Math.abs(region[index] - br) + Math.abs(region[index + 1] - bg) + Math.abs(region[index + 2] - bb);

			if (distance > 180) {
				inkCount++;
				inkR += region[index];
				inkG += region[index + 1];
				inkB += region[index + 2];
			}
		}

		if (inkCount < 30) {
			fail(name, layer.id, 'text-invisible', `${inkCount} contrasting pixels in a ${w}x${h} box`);
			return;
		}

		const luminance = (r, g, b) => {
			const channel = (value) => {
				const v = value / 255;
				return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
			};
			return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
		};

		const inkLum = luminance(inkR / inkCount, inkG / inkCount, inkB / inkCount);
		const bgLum = luminance(br, bg, bb);
		const ratio = (Math.max(inkLum, bgLum) + 0.05) / (Math.min(inkLum, bgLum) + 0.05);

		if (process.env.CPIC_CONTRAST) {
			console.log(`   ${name}/${layer.id} ${ratio.toFixed(2)}:1 ink ${Math.round(inkR / inkCount)},${Math.round(inkG / inkCount)},${Math.round(inkB / inkCount)} on ${modal}`);
		}

		if (ratio < 2.5) {
			fail(name, layer.id, 'low-contrast', `ratio ${ratio.toFixed(2)}:1 (ink ${Math.round(inkR / inkCount)},${Math.round(inkG / inkCount)},${Math.round(inkB / inkCount)} on ${modal})`);
		}
	});

	textRows.forEach((a, index) => {
		textRows.slice(index + 1).forEach((b) => {
			const left = Math.max(a.x, b.x);
			const right = Math.min(a.x + a.width, b.x + b.width);
			const top = Math.max(a.y, b.y);
			const bottom = Math.min(a.bottom, b.bottom);

			if (right - left > 8 && bottom - top > 8) {
				fail(name, `${a.id}/${b.id}`, 'text-overlap', `${Math.round(right - left)}×${Math.round(bottom - top)}px`);
			}
		});
	});

	const undrawn = report.layers.filter((layer) => !layer.drawn);
	undrawn.forEach((layer) => {
		rows.push({ note: `${name}/${layer.id}: undrawn (${layer.reason || 'unknown'})` });
	});

	rows.push({ name, width, height, layers: report.layers.length, drawn, text: textRows.length, ms: report.ms });
}

/* ── Bold must actually change the glyphs ───────────────────────── */

const inkFor = (weight) => {
	const canvas = createCanvas(600, 200);
	const ctx = canvas.getContext('2d');
	ctx.fillStyle = '#000000';
	ctx.fillRect(0, 0, 600, 200);
	ctx.fillStyle = '#FFFFFF';
	ctx.font = `${weight} 80px "Hind Siliguri", sans-serif`;
	ctx.letterSpacing = '0px';
	ctx.textBaseline = 'top';
	ctx.fillText('অর্থনীতি', 10, 20);
	const data = ctx.getImageData(0, 0, 600, 200).data;
	let ink = 0;
	for (let index = 0; index < data.length; index += 4) {
		if (data[index] > 128) {
			ink++;
		}
	}
	return ink;
};

const regularInk = inkFor(400);
const semiboldInk = inkFor(600);

if (semiboldInk <= regularInk * 1.02) {
	fail('bold', 'hind-siliguri', 'bold-has-no-face', `600 ink ${semiboldInk} vs 400 ink ${regularInk}`);
}

/* ── Output ─────────────────────────────────────────────────────── */

if (process.env.CPIC_TRACE) {
	const trace = plans[process.env.CPIC_TRACE];
	if (trace) {
		console.log(JSON.stringify(trace.plan.layers.filter((layer) => layer.id === (process.env.CPIC_TRACE_ID || layer.id)), null, 2));
	}
}

console.log('\n▶ Render doctor\n');
rows.forEach((row) => {
	if (row.note) {
		console.log(`    · ${row.note}`);
		return;
	}
	console.log(`  ${row.name.padEnd(42)} ${row.width}x${row.height}  ${row.drawn}/${row.layers} drawn  ${row.ms}ms`);
});

console.log(`\n  bold: Hind Siliguri 400 = ${regularInk} ink px, 600 = ${semiboldInk} ink px`);

if (!findings.length) {
	console.log(`\n  no rendering faults in ${rows.length} plans`);
	console.log('\nRENDER DOCTOR PASSED');
	process.exit(0);
}

const byKind = new Map();
findings.forEach((finding) => {
	if (!byKind.has(finding.kind)) {
		byKind.set(finding.kind, []);
	}
	byKind.get(finding.kind).push(`${finding.plan}/${finding.id}: ${finding.detail}`);
});

console.log('');
for (const [kind, list] of byKind) {
	console.log(`  ✗ ${kind} (${list.length})`);
	list.slice(0, 8).forEach((line) => console.log('    ' + line));
	if (list.length > 8) {
		console.log(`      … ${list.length - 8} more`);
	}
}

console.log(`\nRENDER DOCTOR FOUND ${findings.length} FAULTS`);
process.exit(1);
