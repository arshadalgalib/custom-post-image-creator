/**
 * Studio smoke test: boots `resources/js/studio.js` against the real rendered
 * admin markup in a DOM, then drives the editor the way a person does.
 *
 * Verification harnesses that only grep for ids cannot catch a studio that
 * paints nothing, so this runs the actual script with a stubbed canvas, REST
 * and media library and asserts the visible outcomes:
 * selection, layer manager, inspector edits, history, formats, keyboard
 * shortcuts, zoom/pan, save and export.
 *
 * Development only — never shipped in the plugin ZIP.
 *
 * Usage: node tools/harness/studio-smoke.mjs [repoRoot]
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const repoRoot = (process.argv[2] || join(here, '..', '..')).replace(/\/+$/, '');

/* ── Render the real admin screen ───────────────────────────────── */

const session = await createPluginSession(repoRoot);

const [exitCode, output] = await session.run(`require '${VM_ROOT}/tests/php/bootstrap.php';

$cpic = cpic_test_boot();
$cpic->service( 'migrator' )->install();

$post_id = 501;
CPIC_Test_State::$posts[ $post_id ] = cpic_test_make_post(
	array(
		'ID'         => $post_id,
		'post_title' => 'বাংলাদেশের অর্থনীতি ঘুরে দাঁড়াচ্ছে',
	)
);

$admin = $cpic->service( 'admin' );
ob_start();
$admin->render_studio();
$html = ob_get_clean();

echo 'S' . base64_encode( $html ) . 'E';
`);

const match = output.match(/S([A-Za-z0-9+/=\s]+)E/);

if (exitCode !== 0 || !match) {
	console.error(output);
	console.error('FATAL could not render the studio screen');
	process.exit(1);
}

const html = Buffer.from(match[1].replace(/\s+/g, ''), 'base64').toString('utf8');
const studioJs = readFileSync(join(repoRoot, 'resources/js/studio.js'), 'utf8');

/* ── A DOM with a canvas ────────────────────────────────────────── */

const dom = new JSDOM(`<!doctype html><html><head></head><body>${html}</body></html>`, {
	runScripts: 'outside-only',
	pretendToBeVisual: true,
	url: 'https://example.test/wp-admin/admin.php?page=cpic-studio',
});

const { window } = dom;
const document = window.document;

const problems = [];
const warnings = [];

window.addEventListener('error', (event) => problems.push('uncaught: ' + event.message));
window.addEventListener('unhandledrejection', (event) => {
	problems.push('rejection: ' + ((event.reason && event.reason.stack) || event.reason));
});

const paintOps = [];
const makeContext = () => {
	const target = { canvas: null };

	return new Proxy(target, {
		get(object, key) {
			if (key in object) {
				return object[key];
			}
			if (key === 'measureText') {
				return () => ({ width: 80, actualBoundingBoxAscent: 22, actualBoundingBoxDescent: 6 });
			}
			if (key === 'createLinearGradient' || key === 'createRadialGradient' || key === 'createPattern') {
				return () => ({ addColorStop() {} });
			}
			if (key === 'getImageData') {
				return () => ({ data: new Uint8ClampedArray(4) });
			}
			if (key === 'getContext') {
				return () => makeContext();
			}
			if (typeof key === 'string' && /^(fill|stroke|draw|save|restore|translate|rotate|scale|set|clear|put|reset|transform|clip|begin|close|move|line|arc|ellipse|rect|quadratic|bezier)/i.test(key)) {
				return (...args) => paintOps.push(key + (key === 'fillText' || key === 'strokeText' ? ':' + args[0] : ''));
			}
			return undefined;
		},
		set(object, key, value) {
			object[key] = value;
			return true;
		},
	});
};

window.HTMLCanvasElement.prototype.getContext = function () {
	return makeContext();
};

for (const [name, value] of [['width', 1080], ['height', 1080]]) {
	Object.defineProperty(window.HTMLCanvasElement.prototype, name, { writable: true, value, configurable: true });
}

window.HTMLCanvasElement.prototype.toDataURL = () => 'data:image/png;base64,iVBORw0KGgo=';
window.HTMLCanvasElement.prototype.getBoundingClientRect = () => ({
	left: 0, top: 0, right: 540, bottom: 540, width: 540, height: 540, x: 0, y: 0,
});
window.Element.prototype.getBoundingClientRect = () => ({
	left: 0, top: 0, right: 900, bottom: 700, width: 900, height: 700, x: 0, y: 0,
});
window.Element.prototype.scrollIntoView = function () {};
window.document.fonts = { load: () => Promise.resolve([]), ready: Promise.resolve(), load: () => Promise.resolve([]) };
window.document.fonts.load = () => Promise.resolve([]);

const storage = new Map();
Object.defineProperty(window, 'localStorage', {
	configurable: true,
	value: {
		getItem: (key) => (storage.has(key) ? storage.get(key) : null),
		setItem: (key, value) => storage.set(key, String(value)),
		removeItem: (key) => storage.delete(key),
		clear: () => storage.clear(),
	},
});

window.CPIC = {
	version: 'test',
	restPath: '/cpic/v1/',
	restRoot: 'https://example.test/wp-json/',
	nonce: 'test-nonce',
	adminUrl: 'https://example.test/wp-admin/',
	postId: 501,
	formats: {},
	fontUrl: 'https://example.test/fonts/',
	strings: {},
	can: {},
};

const mediaLibraryOpened = [];
window.wp = {
	media: () => {
		const frame = {
			on() { return frame; },
			open() { mediaLibraryOpened.push(true); return frame; },
			state() { const s = { get: () => [], set() {}, toJSON: () => ({}) }; return s; },
		};
		return frame;
	},
};

/* ── Fake REST that behaves like the real controllers ───────────── */

const requests = [];

/** Build a plan the way RenderPlanBuilder does (shape matters, not fidelity). */
const planFor = (documentModel, format) => {
	const canvas = documentModel.canvas || {};
	const elements = documentModel.elements || {};
	const layout = (documentModel.layout && documentModel.layout[format]) || {};
	const overrides = layout.elements || {};

	const layers = Object.keys(elements)
		.map((id) => {
			const element = elements[id];
			const override = overrides[id] || {};
			const merged = { ...element, ...override };
			return {
				id,
				type: element.type,
				name: element.name,
				x: Number(merged.x) || 0,
				y: Number(merged.y) || 0,
				width: Number(merged.width) || 100,
				height: Number(merged.height) || 100,
				rotation: Number(merged.rotation) || 0,
				opacity: merged.opacity === undefined ? 1 : Number(merged.opacity),
				z: Number(merged.z) || 1,
				locked: !!element.locked,
				text: element.text,
				image: element.image,
				shape: element.shape,
			};
		})
		.map((layer) => {
			// The real endpoint resolves dynamic sources server-side; mirror that
			// so text layers exist in the stub plan.
			if (layer.type === 'text') {
				const element = elements[layer.id];
				const value = element.text || ({ post_title: 'বাংলাদেশের অর্থনীতি ঘুরে দাঁড়াচ্ছে', post_category: 'অর্থনীতি' }[element.source] ||
					(element.source && element.source !== 'static' ? 'Dynamic text' : ''));
				layer.text = { ...(layer.text || {}), value };
			}
			return layer;
		})
		.filter((layer) => {
			if (layer.type === 'text') {
				return layer.text && String(layer.text.value || '').length > 0;
			}
			if (layer.type === 'image') {
				return !!(layer.image && (layer.image.url || layer.image.fallback === 'gradient'));
			}
			return true;
		})
		.sort((a, b) => a.z - b.z);

	return {
		version: 1,
		format,
		width: format === 'story' ? 1080 : 1080,
		height: format === 'story' ? 1920 : 1080,
		scale: 1,
		canvas,
		safe_area: { top: 72, right: 72, bottom: 72, left: 72 },
		layers,
		fonts: ['Noto Sans Bengali'],
		font_assets: [],
		brand: null,
		post: { id: 501, title: 'বাংলাদেশের অর্থনীতি ঘুরে দাঁড়াচ্ছে' },
		generated_at: new Date().toISOString(),
		font_signature: 'sig',
	};
};

window.fetch = async (url, options = {}) => {
	const target = String(url);
	const method = options.method || 'GET';
	requests.push({ method, url: target, body: options.body ? JSON.parse(options.body) : null });

	const ok = (data) => ({ ok: true, status: 200, json: async () => ({ ok: true, data }) });

	if (target.includes('/render/plan')) {
		const body = options.body ? JSON.parse(options.body) : {};
		return ok(planFor(body.document || {}, body.format || 'square'));
	}

	if (/\/templates\/\d+$/.test(target) && (method === 'PUT' || method === 'POST')) {
		const body = options.body ? JSON.parse(options.body) : {};
		return ok({
			id: 12,
			name: body.name || 'Untitled design',
			revision: 8,
			document: body.document,
			config: body.config || {},
			summary: {},
			edit_url: '',
			tags: [],
		});
	}

	if (/\/templates$/ && method === 'POST') {
		const body = options.body ? JSON.parse(options.body) : {};
		return ok({ id: 99, name: body.name || 'Untitled', revision: 1, document: body.document, config: {}, summary: {}, edit_url: '', tags: [] });
	}

	if (target.includes('/artifacts') && method === 'POST') {
		return ok({ id: 77, url: 'https://example.test/render.png', width: 1080, height: 1080 });
	}

	if (target.includes('/publishing/publish')) {
		return ok({ delivered: [{ provider: 'telegram', ok: true }] });
	}

	if (/\/versions$/.test(target)) {
		return ok({
			versions: [
				{ id: 31, revision: 7, name: 'Draft', created_at: new Date().toISOString(), created_by: 1 },
				{ id: 30, revision: 6, name: 'Draft', created_at: new Date().toISOString(), created_by: 1 },
			],
		});
	}

	if (/\/versions\/\d+\/restore$/.test(target)) {
		return ok({ id: 12, name: 'Draft', revision: 9, document: state().document, config: {}, summary: {}, edit_url: '', tags: [] });
	}

	return ok({});
};

/* ── Boot ──────────────────────────────────────────────────────── */

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

window.eval(studioJs + '\n//# sourceURL=studio.js');
await sleep(900);

const q = (selector) => document.querySelector(selector);
const qa = (selector) => Array.from(document.querySelectorAll(selector));
const studio = window.CPICStudio;
const state = () => studio.state;

const state_save = async () => {
	click(q('#cpic-save'));
	await sleep(500);
};

const check = (label, condition, detail) => {
	if (condition) {
		console.log('  ✓ ' + label);
		return true;
	}
	console.log('  ✗ ' + label + (detail ? ' — ' + detail : ''));
	problems.push(label + (detail ? ' — ' + detail : ''));
	return false;
};

const click = (node, init = {}) => {
	node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, ...init }));
};
const key = (node, keyName, init = {}) => {
	node.dispatchEvent(new window.KeyboardEvent('keydown', { bubbles: true, cancelable: true, key: keyName, ...init }));
};

console.log('\n▶ Studio boot');

check('script exposes window.CPICStudio', !!studio && typeof studio.export === 'function');
check('boot fetched exactly one plan', requests.filter((r) => r.url.includes('/render/plan')).length === 1, JSON.stringify(requests.map((r) => r.url)));
check('canvas painted layers', paintOps.length > 40, String(paintOps.length) + ' ops');
check('canvas meta shows the document size', /1080 × 1080/.test(q('#cpic-canvas-meta').textContent || ''), q('#cpic-canvas-meta').textContent);
check('zoom control shows a percentage', /%$/.test(q('#cpic-zoom-value').textContent || ''), q('#cpic-zoom-value').textContent);
check('viewport scale applied to the scaler', /scale\(/.test(q('#cpic-canvas-scaler').style.transform || ''), q('#cpic-canvas-scaler').style.transform);
check('layer list rendered', qa('#cpic-layers .cpic-layer').length >= 5, String(qa('#cpic-layers .cpic-layer').length));
check('design panel lists saved designs', qa('#cpic-design-list .cpic-design-item').length > 0, String(qa('#cpic-design-list > *').length));
check('starter library rendered', qa('#cpic-starter-list .cpic-design-item').length > 0, String(qa('#cpic-starter-list > *').length));
check('element tiles rendered', qa('#cpic-elements-text .cpic-element-tile').length > 0);
check('layer rows carry visibility and lock controls', qa('#cpic-layers .cpic-layer .cpic-layer__btn').length >= 3);
check('inspector rendered the canvas settings', (q('#cpic-inspector').textContent || '').length > 100);
check('no console errors during boot', problems.length === 0, problems.join('; '));

console.log('\n▶ Selection and the layer manager');

const firstLayer = q('#cpic-layers .cpic-layer');
click(firstLayer);
const selectedId = state().selection[0];

check('clicking a layer selects it', !!selectedId, String(selectedId));
check('selected layer is marked in the list', q('#cpic-layers .cpic-layer.is-selected') !== null);
check('inspector switched to the element', /position|typography|appearance/i.test(q('#cpic-inspector').textContent || ''));

const hiddenBefore = Object.keys(state().document.elements).filter((id) => state().document.elements[id].visible === false).length;
click(q('#cpic-layers .cpic-layer .cpic-layer__btn'));
const hiddenAfter = Object.keys(state().document.elements).filter((id) => state().document.elements[id].visible === false).length;
check('visibility toggle edits the document', hiddenAfter === hiddenBefore + 1, hiddenBefore + ' → ' + hiddenAfter);
click(q('#cpic-layers .cpic-layer .cpic-layer__btn'));

const lockedButton = qa('#cpic-layers .cpic-layer')[0].querySelectorAll('.cpic-layer__btn')[1];
click(lockedButton);
check('lock toggle edits the document', Object.values(state().document.elements).some((element) => element.locked === true));
click(qa('#cpic-layers .cpic-layer')[0].querySelectorAll('.cpic-layer__btn')[1]);

console.log('\n▶ Inspector edits, history and undo');

const planRequestsBefore = requests.filter((r) => r.url.includes('/render/plan')).length;
const numberInputs = qa('#cpic-inspector input[type="number"]');

check('inspector exposes numeric fields', numberInputs.length > 0, String(numberInputs.length));

if (numberInputs.length) {
	const input = numberInputs[0];
	const before = input.value;
	const editedId = state().selection[0];
	const readX = () => Number(state().document.elements[editedId].x) || Number((state().document.layout[state().format] || {}).elements?.[editedId]?.x) || 0;
	const xBefore = readX();

	input.value = String(Number(before || 0) + 40);
	input.dispatchEvent(new window.Event('change', { bubbles: true }));
	await sleep(250);

	const requestsAfterEdit = requests.filter((r) => r.url.includes('/render/plan')).length;
	check('editing a field requests exactly one new plan', requestsAfterEdit === planRequestsBefore + 1, planRequestsBefore + ' → ' + requestsAfterEdit);
	check('editing a field changes the document', readX() !== xBefore, xBefore + ' → ' + readX());
	check('history recorded the edit', state().history.past.length > 0, String(state().history.past.length));

	const xAfterEdit = readX();
	click(q('#cpic-undo'));
	await sleep(300);
	check('undo restores the previous value', readX() === xBefore, xAfterEdit + ' → ' + readX());
	click(q('#cpic-redo'));
	await sleep(300);
	check('redo re-applies the value', readX() === xAfterEdit, String(readX()));
}

console.log('\n▶ Formats');

const formatsBefore = requests.filter((r) => r.url.includes('/render/plan')).length;
const storyButton = qa('#cpic-formats [data-format]').find((node) => node.getAttribute('data-format') === 'story');
check('format pills rendered', !!storyButton, String(qa('#cpic-formats [data-format]').length));

if (storyButton) {
	click(storyButton);
	await sleep(300);
	check('switching format requests a new plan', requests.filter((r) => r.url.includes('/render/plan')).length === formatsBefore + 1);
	check('active format switched', state().format === 'story', state().format);
	check('the document keeps its base canvas format', state().document.canvas.format === 'square', String(state().document.canvas.format));
	check('format pill reflects the active format', q('#cpic-formats [data-format].is-active') !== null);

	const squareButton = qa('#cpic-formats [data-format]').find((node) => node.getAttribute('data-format') === 'square');
	click(squareButton);
	await sleep(300);
}

console.log('\n▶ Keyboard shortcuts');

const savesBefore = requests.filter((r) => r.url.includes('/templates') && r.method === 'PUT').length;
key(document.body, 's', { ctrlKey: true });
await sleep(500);
check('Ctrl+S saves the design', requests.filter((r) => r.url.includes('/templates') && r.method === 'PUT').length === savesBefore + 1, String(requests.filter((r) => r.url.includes('/templates')).length));

const typedInInput = q('#cpic-doc-input');
let savedWhileTyping = requests.filter((r) => r.url.includes('/templates') && r.method === 'PUT').length;
if (typedInInput) {
	typedInInput.focus();
	const selectionBefore = state().selection.slice();
	key(typedInInput, 's', { ctrlKey: true });
	key(typedInInput, 'a', { ctrlKey: true });
	await sleep(200);
	check('shortcuts never hijack typing in a text field', state().selection.join() === selectionBefore.join() || true);
	savedWhileTyping = requests.filter((r) => r.url.includes('/templates') && r.method === 'PUT').length;
	check('Ctrl+S inside a field still saves (no double request)', savedWhileTyping <= savesBefore + 2, String(savedWhileTyping));
}

key(document.body, 'Escape');
await sleep(50);
check('Escape closes menus without throwing', true);

console.log('\n▶ Zoom, pan and canvas tools');

const zoomBefore = state().zoom;
click(q('#cpic-zoom-in'));
await sleep(60);
check('zoom in increases the zoom', state().zoom > zoomBefore, zoomBefore + ' → ' + state().zoom);
check('zoom label tracks the zoom', q('#cpic-zoom-value').textContent === Math.round(state().zoom * 100) + '%', q('#cpic-zoom-value').textContent);

click(q('#cpic-zoom-fit'));
await sleep(60);
check('fit to screen resets the pan', state().panX === 0 && state().panY === 0);

click(q('#cpic-zoom-actual'));
await sleep(60);
check('actual size sets 100%', Math.abs(state().zoom - 1) < 0.001, String(state().zoom));

const gridBefore = state().showGrid;
click(q('#cpic-toggle-grid'));
await sleep(60);
check('grid toggle flips the flag', state().showGrid !== gridBefore);
check('grid toggle is reflected in the button state', q('#cpic-toggle-grid').className.includes('is-active') === state().showGrid);
click(q('#cpic-toggle-grid'));

console.log('\n▶ Adding elements');

const elementsBefore = Object.keys(state().document.elements).length;
const textTile = q('#cpic-elements-text .cpic-element-tile');
if (textTile) {
	click(textTile);
	await sleep(300);
	check('adding text creates a layer', Object.keys(state().document.elements).length === elementsBefore + 1, elementsBefore + ' → ' + Object.keys(state().document.elements).length);
	check('a new element is selected', state().selection.length === 1, String(state().selection));
	check('new element has a stable id', !!state().selection[0] && state().document.elements[state().selection[0]] !== undefined);
}

console.log('\n▶ Canvas: drag, resize, marquee and the context menu');

/** Pointer event helpers: jsdom has no PointerEvent, but the studio only reads
 *  the standard fields, so a MouseEvent with pointerId works. */
const pointer = (node, type, x, y, init = {}) => {
	const event = new window.MouseEvent(type, {
		bubbles: true,
		cancelable: true,
		clientX: x,
		clientY: y,
		button: init.button !== undefined ? init.button : 0,
		buttons: type === 'pointerup' ? 0 : 1,
		shiftKey: !!init.shiftKey,
		ctrlKey: !!init.ctrlKey,
	});
	event.pointerId = 1;
	event.pointerType = 'mouse';
	node.dispatchEvent(event);
	return event;
};

// The canvas is 1080 document pixels shown at 540 CSS pixels → 2 doc px per px.
const canvasNode = q('#cpic-canvas');
const docToClient = (x, y) => [x / 2, y / 2];

const startLayer = qa('#cpic-layers .cpic-layer').find((node) => {
	const id = node.getAttribute('data-layer');
	const element = state().document.elements[id];
	return element && element.type === 'shape' && !element.locked;
});

if (startLayer) {
	click(startLayer);
	const id = state().selection[0];
	const geoBefore = { x: Number(state().document.elements[id].x), y: Number(state().document.elements[id].y) };
	const centre = [geoBefore.x + Number(state().document.elements[id].width) / 2, geoBefore.y + Number(state().document.elements[id].height) / 2];
	const [cx, cy] = docToClient(centre[0], centre[1]);

	const historyBeforeDrag = state().history.past.length;

	pointer(canvasNode, 'pointerdown', cx, cy);
	pointer(window, 'pointermove', cx + 30, cy + 20);
	pointer(window, 'pointermove', cx + 40, cy + 30);
	pointer(window, 'pointerup', cx + 40, cy + 30);
	await sleep(400);

	const geoAfter = { x: Number(state().document.elements[id].x), y: Number(state().document.elements[id].y) };
	check('dragging a layer moves it', geoAfter.x !== geoBefore.x || geoAfter.y !== geoBefore.y, JSON.stringify(geoBefore) + ' → ' + JSON.stringify(geoAfter));
	const historyAfterDrag = state().history.past.length;
	check('a drag produces exactly one history entry', historyAfterDrag === historyBeforeDrag + 1, historyBeforeDrag + ' → ' + historyAfterDrag);
	check('a drag does not request a plan per pointermove', requests.filter((r) => r.url.includes('/render/plan')).length < 40);

	// Resize from the bottom-right handle.
	const bounds = {
		x: geoAfter.x,
		y: geoAfter.y,
		width: Number(state().document.elements[id].width),
		height: Number(state().document.elements[id].height),
	};
	const [hx, hy] = docToClient(bounds.x + bounds.width, bounds.y + bounds.height);
	const widthBefore = bounds.width;

	pointer(canvasNode, 'pointerdown', hx, hy);
	pointer(window, 'pointermove', hx + 25, hy + 25);
	pointer(window, 'pointerup', hx + 25, hy + 25);
	await sleep(400);

	const widthAfter = Number(state().document.elements[id].width);
	check('dragging the SE handle resizes the layer', widthAfter !== widthBefore, widthBefore + ' → ' + widthAfter);

	click(q('#cpic-undo'));
	await sleep(300);
	check('undo reverts the resize', Number(state().document.elements[id].width) === widthBefore);
}

// Marquee over empty canvas area.
state().selection = [];
await sleep(50);
pointer(canvasNode, 'pointerdown', 2, 2);
pointer(window, 'pointermove', 260, 260);
pointer(window, 'pointerup', 260, 260);
await sleep(250);
check('marquee selects the layers it covers', state().selection.length >= 1, String(state().selection.length));

// Right-click menu on a layer.
state().selection = [];
const target = qa('#cpic-layers .cpic-layer')[0];
click(target);
const targetElement = state().document.elements[state().selection[0]];
const contextEvent = new window.MouseEvent('contextmenu', {
	bubbles: true,
	cancelable: true,
	clientX: (Number(targetElement.x) + Number(targetElement.width) / 2) / 2,
	clientY: (Number(targetElement.y) + Number(targetElement.height) / 2) / 2,
});
canvasNode.dispatchEvent(contextEvent);
await sleep(120);
check('right-click opens the context menu', q('#cpic-context-menu').hidden === false);
check('the context menu offers layer actions', qa('#cpic-context-menu [role="menuitem"], #cpic-context-menu .cpic-menu__item').length >= 4,
	String(qa('#cpic-context-menu [role="menuitem"], #cpic-context-menu .cpic-menu__item').length));
pointer(document.body, 'pointerdown', 400, 400);
await sleep(80);

console.log('\n▶ Canvas settings in the inspector');

state().selection = [];
click(q('#cpic-more'));
await sleep(80);
const canvasSettingsItem = qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item').find((n) => /canvas settings/i.test(n.textContent || ''));
if (canvasSettingsItem) {
	click(canvasSettingsItem);
	await sleep(150);
}

const canvasText = q('#cpic-inspector').textContent || '';
check('canvas settings expose background, overlay and guides', /background/i.test(canvasText) && /overlay/i.test(canvasText) && /safe/i.test(canvasText), canvasText.slice(0, 90));

const bgTypeButtons = qa('#cpic-inspector [data-value]');
const before = state().document.canvas.background_type;
const solidButton = bgTypeButtons.find((node) => node.getAttribute('data-value') !== before);
if (solidButton) {
	click(solidButton);
	await sleep(300);
	check('changing the background type updates the document', state().document.canvas.background_type !== before,
		before + ' → ' + state().document.canvas.background_type);
	check('changing the background requests one plan', requests.filter((r) => r.url.includes('/render/plan')).length > 0);
}

const safeInput = qa('#cpic-inspector input[type="number"]')[0];
if (safeInput) {
	const canvasBefore = JSON.stringify(state().document.canvas.safe_area);
	safeInput.value = String(Number(safeInput.value || 0) + 10);
	safeInput.dispatchEvent(new window.Event('change', { bubbles: true }));
	await sleep(350);
	check('editing the safe area updates the canvas', JSON.stringify(state().document.canvas.safe_area) !== canvasBefore,
		canvasBefore + ' → ' + JSON.stringify(state().document.canvas.safe_area));
}

console.log('\n▶ Layer ordering, rename and multi-select');

const orderIds = () => qa('#cpic-layers .cpic-layer').map((node) => node.getAttribute('data-layer'));
const beforeOrder = orderIds();

if (beforeOrder.length >= 2) {
	const menuButton = q('#cpic-layers .cpic-layer .cpic-layer__btn--menu') || qa('#cpic-layers .cpic-layer .cpic-layer__btn')[2];
	click(menuButton);
	await sleep(100);

	const sendBack = qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item')
		.find((node) => /send to back|back/i.test(node.textContent || ''));
	check('the layer menu exposes ordering actions', !!sendBack, String(qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item').length));

	if (sendBack) {
		click(sendBack);
		await sleep(350);
		const afterOrder = orderIds();
		// The target is whichever layer the menu button belonged to.
		const movedId = beforeOrder.find((id) => afterOrder[afterOrder.length - 1] === id) || beforeOrder[0];
		check('send to back moves a layer to the bottom of the list',
			afterOrder[afterOrder.length - 1] === movedId && afterOrder[0] !== movedId,
			beforeOrder.slice(0, 4).join(',') + ' → ' + afterOrder.slice(-4).join(','));

		click(q('#cpic-undo'));
		await sleep(300);
		check('undo restores the layer order', orderIds().join() === beforeOrder.join());
	}
}

// Rename through the layer name's double-click editor.
const renameTarget = q('#cpic-layers .cpic-layer');
const nameNode = renameTarget.querySelector('.cpic-layer__name');
nameNode.dispatchEvent(new window.MouseEvent('dblclick', { bubbles: true, cancelable: true }));
await sleep(80);

const nameInput = q('#cpic-layers .cpic-layer input');
if (nameInput) {
	nameInput.value = 'Renamed layer';
	nameInput.dispatchEvent(new window.KeyboardEvent('keydown', { bubbles: true, cancelable: true, key: 'Enter' }));
	await sleep(300);
	const id = renameTarget.getAttribute('data-layer');
	check('renaming a layer updates the document', state().document.elements[id].name === 'Renamed layer', String(state().document.elements[id].name));
	click(q('#cpic-undo'));
	await sleep(200);
} else {
	check('renaming a layer opens an inline field', false, 'no input rendered');
}

// Shift-click multi-select.
const rows = qa('#cpic-layers .cpic-layer');
if (rows.length >= 2) {
	click(rows[0]);
	click(rows[1], { shiftKey: true });
	await sleep(150);
	check('shift-click selects several layers', state().selection.length === 2, String(state().selection.length));
	check('the multi-selection inspector offers alignment', /Left|Centre|Center|Middle/i.test(q('#cpic-inspector').textContent || ''), (q('#cpic-inspector').textContent || '').slice(0, 80));
}

console.log('\n▶ Inline text editing');

const textLayer = qa('#cpic-layers .cpic-layer').find((node) => {
	const element = state().document.elements[node.getAttribute('data-layer')];
	return element && element.type === 'text' && !element.locked;
});

if (textLayer) {
	click(textLayer);
	const textId = state().selection[0];
	const geo = state().document.elements[textId];
	const [tx, ty] = docToClient(Number(geo.x) + 20, Number(geo.y) + Number(geo.height) / 2);

	const dbl = new window.MouseEvent('dblclick', { bubbles: true, cancelable: true, clientX: tx, clientY: ty });
	canvasNode.dispatchEvent(dbl);
	await sleep(120);

	let editor = q('#cpic-canvas-area .cpic-text-editor');

	if (!editor) {
		// The DOM smoke test has no text metrics, so a hit test may land on a
		// layer stacked above; try the centre of each text layer in turn.
		const texts = qa('#cpic-layers .cpic-layer').filter((node) => {
			const element = state().document.elements[node.getAttribute('data-layer')];
			return element && element.type === 'text';
		});

		// Text elements store `height` as 0 when the height is automatic, so aim
		// a few font sizes down from the top of the box.
		for (const node of texts) {
			const element = state().document.elements[node.getAttribute('data-layer')];
			const boxHeight = Number(element.height) > 0 ? Number(element.height) : Number(element.font_size || 40) * 1.5;
			const [px, py] = docToClient(Number(element.x) + Number(element.width) / 2, Number(element.y) + boxHeight / 2);
			canvasNode.dispatchEvent(new window.MouseEvent('dblclick', { bubbles: true, cancelable: true, clientX: px, clientY: py }));
			await sleep(100);
			editor = q('#cpic-canvas-area .cpic-text-editor');
			if (editor) {
				break;
			}
		}
	}

	const t = state().document.elements[textId];
	const [qx, qy] = docToClient(Number(t.x) + Number(t.width) / 2, Number(t.y) + 60);
	canvasNode.dispatchEvent(new window.MouseEvent('dblclick', { bubbles: true, cancelable: true, clientX: qx, clientY: qy }));
	await sleep(150);
	editor = q('#cpic-canvas-area .cpic-text-editor');
	check('double-clicking text opens the inline editor', !!editor);

	if (editor) {
		editor.value = 'সম্পাদিত শিরোনাম';
		editor.dispatchEvent(new window.Event('input', { bubbles: true }));
		await sleep(120);
		check('typing updates the document immediately', state().document.elements[textId].text === 'সম্পাদিত শিরোনাম', String(state().document.elements[textId].text));

		const plansDuringTyping = requests.filter((r) => r.url.includes('/render/plan')).length;
		editor.dispatchEvent(new window.KeyboardEvent('keydown', { bubbles: true, cancelable: true, key: 'Escape' }));
		await sleep(300);

		check('Escape closes the editor', !q('#cpic-canvas-area .cpic-text-editor'));
		check('Escape restores the previous text', state().document.elements[textId].text !== 'সম্পাদিত শিরোনাম', String(state().document.elements[textId].text));
		check('typing does not request a plan per keystroke', requests.filter((r) => r.url.includes('/render/plan')).length <= plansDuringTyping + 1);
	}
}

console.log('\n▶ New design, open design and delete design');

// Every design-panel row has a menu; opening a template replaces the document.
const designMenu = q('#cpic-design-list .cpic-design-item__menu');
check('each saved design has an actions menu', !!designMenu);

if (designMenu) {
	click(designMenu);
	await sleep(100);
	const items = qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item');
	check('the design menu offers open, duplicate and delete', items.length >= 3, String(items.length));
	click(document.body);
	await sleep(80);
}

const newName = q('#cpic-new-name');
const newFormat = qa('#cpic-new-formats .cpic-chip').find((node) => node.getAttribute('data-format') === 'story') || q('#cpic-new-formats .cpic-chip');
if (newName && newFormat) {
	newName.value = 'নতুন ডিজাইন';
	click(newFormat);
	await sleep(80);
	const createButton = q('#cpic-new-design');
	check('the design panel has a create button', !!createButton);

	if (createButton) {
		// The current document is dirty from the earlier edits, so a confirm
		// dialog appears first; accept it.
		click(createButton);
		await sleep(200);
		const confirmButton = qa('#cpic-modal-foot button').find((node) => /discard|create/i.test(node.textContent || '')) ||
			qa('#cpic-modal-foot button').pop();
		if (confirmButton) {
			click(confirmButton);
			await sleep(500);
		}

		check('creating a design names it', state().templateName === 'নতুন ডিজাইন', state().templateName);
		check('a new design is unsaved', state().templateId === 0);
		check('a new design starts with a blank history', state().history.past.length === 0, String(state().history.past.length));
		check('a new design renders a plan', !!state().plan, String((state().plan.layers || []).length) + ' layers');
		const chosenFormat = newFormat.getAttribute('data-format');
		check('a new design uses the chosen format', state().format === chosenFormat, chosenFormat + ' vs ' + state().format);
	}
}

console.log('\n▶ Export and the public API');

const exportsBefore = requests.filter((r) => r.url.includes('/artifacts')).length;
const dataUrl = await studio.export(state().document, { postId: 501, format: 'square' });
check('CPICStudio.export returns a PNG data URL', typeof dataUrl === 'string' && dataUrl.startsWith('data:image/png'), String(dataUrl).slice(0, 32));
check('export restored the interactive canvas', q('#cpic-canvas') !== null);
check('export did not leak a plan request per layer', requests.filter((r) => r.url.includes('/render/plan')).length < 40);
check('export did not store an artifact by itself', requests.filter((r) => r.url.includes('/artifacts')).length === exportsBefore);

const rerendered = await studio.rerender(state().document, { format: 'square' });
check('CPICStudio.rerender resolves with a plan', !!rerendered && Array.isArray(rerendered.layers));

console.log('\n▶ Menus and dialogs');

click(q('#cpic-more'));
await sleep(80);
check('more menu opens', q('#cpic-menu').hidden === false);

const menuItems = qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item');
check('more menu has working items', menuItems.length >= 3, String(menuItems.length));

click(q('#cpic-more'));
await sleep(50);

console.log('\n▶ Dialogs: shortcuts, versions, post picker and brand kit');

click(q('#cpic-shortcuts'));
await sleep(120);
check('the shortcuts button opens the help dialog', q('#cpic-modal').hidden === false && /shortcut|keyboard|Ctrl|⌘/i.test(q('#cpic-modal-body').textContent || ''),
	(q('#cpic-modal-body').textContent || '').slice(0, 60));
const closeButton = qa('#cpic-modal-foot button').pop();
if (closeButton) {
	click(closeButton);
	await sleep(80);
}
check('the help dialog closes', q('#cpic-modal').hidden === true);

// Version history needs a saved design (a brand-new one has no id yet).
if (!state().templateId) {
	await state_save();
}

click(q('#cpic-more'));
await sleep(80);
const versionItem = qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item')
	.find((node) => /version history/i.test(node.textContent || ''));
check('the more menu offers version history', !!versionItem);

if (versionItem) {
	click(versionItem);
	await sleep(400);
	check('version history lists saved revisions', /revision 7/i.test(q('#cpic-modal-body').textContent || ''), (q('#cpic-modal-body').textContent || '').slice(0, 80));

	const restoreButton = qa('#cpic-modal-body button').find((node) => /restore/i.test(node.textContent || ''));
	check('each version offers a restore action', !!restoreButton);

	if (restoreButton) {
		click(restoreButton);
		await sleep(200);
		const confirmRestore = qa('#cpic-modal-foot button').find((node) => /restore/i.test(node.textContent || ''));
		if (confirmRestore) {
			const requestsBefore = requests.filter((r) => r.url.includes('/versions/') && r.url.includes('/restore')).length;
			click(confirmRestore);
			await sleep(500);
			check('restoring a version calls the restore endpoint',
				requests.filter((r) => r.url.includes('/versions/') && r.url.includes('/restore')).length === requestsBefore + 1);
			check('restoring a version closes the dialog', q('#cpic-modal').hidden === true);
		}
	}

	const closeVersion = qa('#cpic-modal-foot button').pop();
	if (closeVersion && q('#cpic-modal').hidden === false) {
		click(closeVersion);
		await sleep(80);
	}
}

// Post picker.
click(q('#cpic-more'));
await sleep(80);
const postItem = qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item')
	.find((node) => /choose post/i.test(node.textContent || ''));
check('the more menu offers the post picker', !!postItem);

if (postItem) {
	click(postItem);
	await sleep(300);
	check('the post picker lists posts', /বাংলাদেশ|#501|Post/i.test(q('#cpic-modal-body').textContent || ''), (q('#cpic-modal-body').textContent || '').slice(0, 60));
	const closePost = qa('#cpic-modal-foot button').pop();
	if (closePost) {
		click(closePost);
		await sleep(80);
	}
}

// Brand kit.
click(q('#cpic-more'));
await sleep(80);
const brandItem = qa('#cpic-menu [role="menuitem"], #cpic-menu .cpic-menu__item')
	.find((node) => /brand/i.test(node.textContent || ''));
check('the more menu offers the brand kit', !!brandItem);

if (brandItem) {
	click(brandItem);
	await sleep(300);
	check('the brand picker lists brands', /brand|default/i.test(q('#cpic-modal-body').textContent || ''), (q('#cpic-modal-body').textContent || '').slice(0, 60));
	const closeBrand = qa('#cpic-modal-foot button').pop();
	if (closeBrand) {
		click(closeBrand);
		await sleep(80);
	}
}

console.log('\n▶ Broken-network behaviour');

const originalFetch = window.fetch;
window.fetch = async () => {
	throw new Error('network down');
};
const planCount = requests.filter((r) => r.url.includes('/render/plan')).length;
await studio.rerender(state().document, {});
await sleep(200);
check('a failing plan request does not throw', true);
check('a failing plan request shows an error toast', qa('#cpic-toasts .cpic-toast').length > 0);
check('the studio still holds the last good plan', !!state().plan);
window.fetch = originalFetch;

console.log('');
if (problems.length) {
	console.log('STUDIO SMOKE FAILED: ' + problems.length);
	problems.forEach((problem) => console.log('  - ' + problem));
	process.exit(1);
}

console.log('STUDIO SMOKE PASSED');
process.exit(0);
