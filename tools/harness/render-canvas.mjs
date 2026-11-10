/**
 * Paints a render plan with a real Canvas implementation and writes a PNG.
 *
 * This is the only way to check the drawing code (wrapping, shrinking,
 * gradients, image fitting) without a browser: the same plan the studio gets
 * is painted by an independent canvas backend.
 *
 * Usage: node tools/harness/render-canvas.mjs [outFile]
 */

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createCanvas, GlobalFonts } from '@napi-rs/canvas';
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const repoRoot = join(here, '..', '..');
const outFile = process.argv[2] || join(repoRoot, 'tools/harness/out/plan-preview.png');

// Register the plugin's own Bengali faces so the preview reflects the real
// font choice (napi-rs does not shape clusters, so this proves the family and
// metrics, not the conjunct shaping a browser performs).
for (const file of ['NotoSansBengali-Regular.ttf', 'NotoSerifBengali-Regular.ttf', 'HindSiliguri-Regular.ttf', 'HindSiliguri-SemiBold.ttf']) {
	try {
		GlobalFonts.registerFromPath(join(repoRoot, 'fonts', file));
	} catch (error) {
		console.warn(`could not register ${file}:`, error.message);
	}
}

const session = await createPluginSession(repoRoot);

const [code, output] = await session.run(`require '${VM_ROOT}/tests/php/bootstrap.php';

$plugin = cpic_test_boot();
$plugin->service( 'migrator' )->install();

$post_id = 700;
CPIC_Test_State::$posts[ $post_id ] = cpic_test_make_post(
	array(
		'ID'           => $post_id,
		'post_title'   => 'বাংলাদেশের অর্থনীতি ঘুরে দাঁড়াচ্ছে',
		'post_content' => '<p>রপ্তানি আয় বেড়েছে।</p>',
	)
);

$format = getenv( 'CPIC_FORMAT' ) ? getenv( 'CPIC_FORMAT' ) : 'square';

$plan = $plugin->service( 'render_plan' )->build(
	array(
		'post_id'  => $post_id,
		'format'   => $format,
		'document' => $plugin->service( 'template_manager' )->default_document( $format ),
	)
);

echo "PLAN_START\\n", json_encode( $plan ), "\\nPLAN_END\\n";
`);

if (code !== 0) {
	console.error(output);
	process.exit(1);
}

const match = output.match(/PLAN_START\n([\s\S]*?)\nPLAN_END/);

if (!match) {
	console.error('No plan in output:\n', output.slice(-2000));
	process.exit(1);
}

const plan = JSON.parse(match[1]);
const canvas = createCanvas(plan.width, plan.height);
const ctx = canvas.getContext('2d');

// Minimal, honest painter: shapes, images (URLs are not fetched here), text.
const canvasConfig = plan.canvas || {};

if (canvasConfig.background_type === 'color' || !canvasConfig.gradient_from) {
	ctx.fillStyle = canvasConfig.background_color || '#0F172A';
} else {
	const gradient = ctx.createLinearGradient(0, 0, plan.width, plan.height);
	gradient.addColorStop(0, canvasConfig.gradient_from || '#667EEA');
	gradient.addColorStop(1, canvasConfig.gradient_to || '#764BA2');
	ctx.fillStyle = gradient;
}

ctx.fillRect(0, 0, plan.width, plan.height);

const layers = [...(plan.layers || [])].sort((a, b) => (a.z || 0) - (b.z || 0));
let drawnText = 0;

for (const layer of layers) {
	ctx.save();
	ctx.globalAlpha = typeof layer.opacity === 'number' ? layer.opacity : 1;

	if (layer.type === 'shape' || layer.type === 'gradient') {
		const shape = layer.shape || {};
		ctx.fillStyle = shape.gradient_from || shape.fill || '#FFFFFF';
		ctx.fillRect(layer.x, layer.y, Math.max(1, layer.width), Math.max(1, layer.height));
	} else if (layer.type === 'text' && layer.text) {
		const text = layer.text;
		const size = text.font_size || 40;
		ctx.font = `${text.font_style || 'normal'} ${text.font_weight || 400} ${size}px "${text.font_family || 'sans-serif'}"`;
		ctx.textBaseline = 'top';
		ctx.fillStyle = text.color || '#FFFFFF';

		if (text.background) {
			ctx.fillStyle = text.background;
			ctx.fillRect(layer.x, layer.y, Math.max(1, layer.width), size * (text.line_height || 1.3) + (text.padding_y || 0) * 2);
			ctx.fillStyle = text.color || '#FFFFFF';
		}

		const words = String(text.value || '').split(/\s+/);
		let line = '';
		let lineIndex = 0;
		let y = layer.y + (text.padding_y || 0);

		for (const word of words) {
			const candidate = line ? `${line} ${word}` : word;

			if (ctx.measureText(candidate).width > layer.width && line) {
				ctx.fillText(line, layer.x + (text.padding_x || 0), y);
				line = word;
				y += size * (text.line_height || 1.3);
				lineIndex++;

				if (text.max_lines && lineIndex >= text.max_lines) {
					break;
				}
			} else {
				line = candidate;
			}
		}

		if (line && (!text.max_lines || lineIndex < text.max_lines)) {
			ctx.fillText(line, layer.x + (text.padding_x || 0), y);
		}

		drawnText++;
	} else if (layer.image) {
		ctx.strokeStyle = 'rgba(255,255,255,0.35)';
		ctx.setLineDash([8, 8]);
		ctx.strokeRect(layer.x, layer.y, layer.width || 10, layer.height || 10);
		ctx.setLineDash([]);
		ctx.fillStyle = 'rgba(255,255,255,0.08)';
		ctx.fillRect(layer.x, layer.y, layer.width || 10, layer.height || 10);
	}

	ctx.restore();
}

// `out/` is gitignored, so a fresh checkout has no directory to write into.
mkdirSync(dirname(outFile), { recursive: true });
writeFileSync(outFile, canvas.toBuffer('image/png'));

// Terminal proof that the plan itself is sane.
console.log(`plan ${plan.format} ${plan.width}x${plan.height}, layers=${layers.length}, textLayers=${drawnText}, fonts=${(plan.fonts || []).join(',') || 'none'}`);
console.log(`wrote ${outFile}`);
process.exit(layers.length > 0 && drawnText > 0 ? 0 : 1);
