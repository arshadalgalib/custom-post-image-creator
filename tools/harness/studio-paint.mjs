/**
 * Studio paint test: runs the studio's own painter against a real Canvas
 * implementation (napi-rs) and inspects the pixels.
 *
 * `render-canvas.mjs` proves the server's plan is sane; this proves the
 * *studio* draws that plan — the same `paint()` the preview and the export
 * use. It catches regressions the DOM smoke test cannot see: text that never
 * reaches the canvas, a background that is not painted, a shape that clips the
 * whole document, or an export sized differently from the plan.
 *
 * Development only — never shipped in the plugin ZIP.
 *
 * Usage: node tools/harness/studio-paint.mjs [repoRoot]
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createCanvas, GlobalFonts } from '@napi-rs/canvas';
import { JSDOM } from 'jsdom';
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const repoRoot = (process.argv[2] || join(here, '..', '..')).replace(/\/+$/, '');
const outDir = join(here, 'out');

mkdirSync(outDir, { recursive: true });

/* ── A real render plan from the real builder ───────────────────── */

for (const file of ['NotoSansBengali-Regular.ttf', 'NotoSerifBengali-Regular.ttf', 'HindSiliguri-Regular.ttf']) {
	try {
		GlobalFonts.registerFromPath(join(repoRoot, 'fonts', file));
	} catch (error) {
		console.warn(`could not register ${file}:`, error.message);
	}
}

const session = await createPluginSession(repoRoot);

const [exitCode, output] = await session.run(`require '${VM_ROOT}/tests/php/bootstrap.php';

$plugin = cpic_test_boot();
$plugin->service( 'migrator' )->install();

$post_id = 811;
CPIC_Test_State::$posts[ $post_id ] = cpic_test_make_post(
	array(
		'ID'         => $post_id,
		'post_title' => 'বাংলাদেশের অর্থনীতি ঘুরে দাঁড়াচ্ছে',
	)
);

$templates = $plugin->service( 'template_manager' );
$template  = $templates->default_template();
$builder   = $plugin->service( 'render_plan' );

$plans = array();

foreach ( array( 'square', 'story' ) as $format ) {
	$plan = $builder->build(
		array(
			'document' => $template['document'],
			'format'   => $format,
			'post_id'  => $post_id,
		)
	);
	if ( is_wp_error( $plan ) ) {
		echo 'ERR ' . $format . ' ' . $plan->get_error_message() . "\\n";
		continue;
	}
	$plans[ $format ] = array(
		'plan'     => $plan,
		'document' => $template['document'],
	);
}

echo 'S' . base64_encode( wp_json_encode( $plans ) ) . 'E';
`);

const match = output.match(/S([A-Za-z0-9+/=\s]+)E/);

if (exitCode !== 0 || !match) {
	console.error(output);
	console.error('FATAL could not build a render plan');
	process.exit(1);
}

const plans = JSON.parse(Buffer.from(match[1].replace(/\s+/g, ''), 'base64').toString('utf8'));

/* ── The studio script in a DOM, with a real canvas ─────────────── */

const studioJs = readFileSync(join(repoRoot, 'resources/js/studio.js'), 'utf8');
const realCanvasFor = new Map();

const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', {
	runScripts: 'outside-only',
	pretendToBeVisual: true,
	url: 'https://example.test/',
});

const { window } = dom;
const problems = [];

window.addEventListener('error', (event) => problems.push(event.message));

/**
 * A canvas element backed by napi-rs.
 *
 * jsdom's <canvas> is a stub, so the element keeps its own width/height and
 * forwards `getContext`/`toDataURL` to a native canvas of the same size. Any
 * change of size re-allocates the native buffer, exactly like a browser.
 */
const installRealCanvas = () => {
	const previous = window.HTMLCanvasElement.prototype.getContext;

	Object.defineProperty(window.HTMLCanvasElement.prototype, 'getContext', {
		configurable: true,
		value(type) {
			if (type !== '2d') {
				return null;
			}

			const width = Math.max(1, Math.round(this.width || 1));
			const height = Math.max(1, Math.round(this.height || 1));
			const key = width + 'x' + height;
			let entry = realCanvasFor.get(this);

			if (!entry || entry.key !== key) {
				const native = createCanvas(width, height);
				entry = { key, native, ctx: native.getContext('2d') };
				realCanvasFor.set(this, entry);
			}

			return entry.ctx;
		},
	});

	Object.defineProperty(window.HTMLCanvasElement.prototype, 'toDataURL', {
		configurable: true,
		value() {
			const entry = realCanvasFor.get(this);
			return entry ? entry.native.toDataURL('image/png') : 'data:image/png;base64,';
		},
	});

	return previous;
};

installRealCanvas();

window.Element.prototype.getBoundingClientRect = () => ({ left: 0, top: 0, right: 800, bottom: 600, width: 800, height: 600, x: 0, y: 0 });
window.document.fonts = { load: () => Promise.resolve([]), ready: Promise.resolve() };

// Images resolve as "no image"; the default document's text layers are the
// subject here, and a missing photo must degrade to the placeholder, not throw.
class StubImage {
	constructor() {
		this.width = 1200;
		this.height = 800;
		this.crossOrigin = null;
		setTimeout(() => this.onerror && this.onerror(), 0);
	}
}

window.Image = StubImage;

window.CPIC = { version: 'test', restPath: '/cpic/v1/', restRoot: 'https://example.test/wp-json/', nonce: 'n', formats: {}, strings: {}, can: {} };
window.fetch = async () => ({ ok: false, status: 500, json: async () => ({ message: 'offline' }) });

window.eval(studioJs + '\n//# sourceURL=studio.js');

const studio = window.CPICStudio;
const problemsStart = problems.length;
const results = [];

const sample = (canvasEntry, x, y) => {
	const data = canvasEntry.ctx.getImageData(x, y, 1, 1).data;
	return [data[0], data[1], data[2], data[3]];
};

/** Count distinct colours in a grid — a blank canvas has exactly one. */
const colourCount = (entry, width, height) => {
	const seen = new Set();
	for (let y = 4; y < height; y += Math.max(4, Math.floor(height / 40))) {
		for (let x = 4; x < width; x += Math.max(4, Math.floor(width / 40))) {
			const data = entry.ctx.getImageData(x, y, 1, 1).data;
			seen.add(data.join(','));
		}
	}
	return seen.size;
};

for (const [format, entry_] of Object.entries(plans)) {
	const plan = entry_.plan;
	const before = problems.length;

	// `export()` asks the REST endpoint for a plan; offline here, so the plan
	// from PHP is injected directly and painted through the studio's state.
	studio.state.plan = plan;
	studio.state.document = entry_.document;
	studio.state.format = format;

	const dataUrl = await (async () => {
		const previous = window.fetch;
		window.fetch = async () => ({ ok: true, status: 200, json: async () => ({ ok: true, data: plan }) });
		try {
			return await studio.export(entry_.document, { format });
		} finally {
			window.fetch = previous;
		}
	})();

	const width = Math.round(plan.width);
	const height = Math.round(plan.height);

	// The export path builds its own canvas; find the matching native buffer.
	const entries = Array.from(realCanvasFor.values()).filter((candidate) => candidate.key === width + 'x' + height);
	const entry = entries[entries.length - 1];

	results.push({
		format,
		width,
		height,
		dataUrlLength: dataUrl.length,
		colours: colourCount(entry, width, height),
		topLeft: sample(entry, 2, 2),
		centre: sample(entry, Math.floor(width / 2), Math.floor(height / 2)),
		problems: problems.slice(before),
	});

	writeFileSync(join(outDir, `studio-paint-${format}.png`), Buffer.from(dataUrl.split(',')[1], 'base64'));
}

/* ── Assertions ─────────────────────────────────────────────────── */

const failures = [];

const check = (label, condition, detail) => {
	if (condition) {
		console.log('  ✓ ' + label);
	} else {
		console.log('  ✗ ' + label + (detail ? ' — ' + detail : ''));
		failures.push(label);
	}
};

console.log('\n▶ Studio painter');

check('no script errors while painting', problems.length === problemsStart, problems.slice(problemsStart).join('; '));

for (const result of results) {
	console.log(`\n  ${result.format} (${result.width}×${result.height})`);
	check('export produced a PNG payload', result.dataUrlLength > 5000, String(result.dataUrlLength));
	check('the canvas is not blank', result.colours > 3, result.colours + ' colours');
	check('the background is opaque', result.topLeft[3] === 255, String(result.topLeft[3]));
	check('text and shapes painted over the background', result.centre.join() !== result.topLeft.join(), result.topLeft.join() + ' / ' + result.centre.join());
}

console.log('\n  previews → ' + join('tools/harness/out', 'studio-paint-*.png'));

if (failures.length) {
	console.log('\nSTUDIO PAINT FAILED: ' + failures.length);
	process.exit(1);
}

console.log('\nSTUDIO PAINT PASSED');
process.exit(0);
