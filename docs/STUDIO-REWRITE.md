# Design Studio rewrite — implementation report

Branch: `arena/f20efa0b-custom-post-image-creator` · commit `78fd22f` · version 7.1.0

---

## 1. What changed, in one paragraph

`templates/admin/studio.php`, `resources/js/studio.js` and `resources/css/studio.css`
were rebuilt as one app-style editor: an app header, a three-tab sidebar with a real
layer manager, a zoomable/pannable workspace with rulers, guides and snapping, and a
context-sensitive inspector. The render-plan architecture is untouched — the studio
still POSTs the document to `cpic/v1/render/plan` and paints the returned plan with the
same painter the export uses, so Bengali shaping, font fallback, image fitting and the
saved PNG cannot drift. No PHP rendering was added, no schema field was invented, no
element id or endpoint was renamed.

Beyond the visible work, wiring the new gates surfaced **nine real bugs** at the time (section 5), and a later rendering audit added eleven more (see `docs/RENDERING-FIXES.md`) —
including one where no plan ever reached the canvas and one where every browser render
would have been rejected by the artifacts endpoint.

---

## 2. Files

| File | Lines | What it is |
| --- | ---: | --- |
| `templates/admin/studio.php` | 587 | App shell + boot payload |
| `resources/js/studio.js` | 9 200 | The whole editor (vanilla JS, one IIFE, `window.CPICStudio` preserved) |
| `resources/css/studio.css` | 2 782 | All styles, scoped under `.cpic-studio`, no CDN, no emoji, inline SVG only |
| `tools/harness/studio-smoke.mjs` | 977 | **New gate**: drives the real editor script in a DOM (106 checks) |
| `tools/harness/render-doctor.mjs` | 470 | **New gate**: paints every starter in every format and measures the result |
| `tools/harness/studio-paint.mjs` | 268 | **New gate**: paints real plans through the studio's painter, inspects pixels |
| `src/REST/Controllers/ArtifactsController.php` | +6 | Accepts `binary` as well as `data` |
| `tests/php/run-core.php` | +32 | Regression tests for both payload fields + empty-render rejection |
| `tools/harness/verify.mjs` | +2 | Runs the two new gates |
| `docs/ARCHITECTURE.md` | +55 | "The studio" section: the invariants below |
| `tools/harness/README.md`, `README.md`, `readme.txt` | — | Harness table, feature table, 7.1.0 changelog + upgrade notice |

Net: **+12 941 / −1 234** across 15 files.

---

## 3. Features, mapped to the request

### Header
Design name with click-to-rename (Enter commits, Escape reverts) · honest save-status pill ·
undo/redo with live enable state · format pills from `Formats::all()` · Preview · Save ·
Export menu · Publish · More menu · shortcut hint. Every item is wired; nothing is decorative.

### Left sidebar (`Design` / `Elements` / `Layers`)
- **Design**: saved designs (search, count, open, duplicate, delete, per-row action menu), starter
  library, "new design" with a format picker that is remembered.
- **Elements**: text (headline/subhead/body/label), media (library, upload, logo, watermark),
  shapes (rect, rounded, circle, line, gradient).
- **Layers**: icon, name, meta, visibility, lock, actions menu, drag-reorder, keyboard
  activation, F2/double-click rename, shift/meta multi-select.

### Workspace
Zoom in/out/fit/actual + `Ctrl`/`⌘`+wheel + pinch; pan with space, middle button or two fingers;
horizontal + vertical rulers with a readable tick ladder; grid, safe area, checkerboard,
alignment guides, contextual selection toolbar, marquee, snapping with a zoom-aware threshold.

### Canvas interaction
8 resize handles + rotation handle, aspect-ratio preserving corner drags with min/max clamps,
shift axis lock, arrow-key nudging (shift = 10 px), right-click context menu, double-click to
edit text, double-click an image to replace it, drag-and-drop upload onto the workspace.

### Inspector
Position (X/Y/W/H/rotation/opacity, align-to-canvas, format-scoped notice) · Typography
(family, weight, style, size, line height, letter spacing, alignment, vertical alignment,
max lines, auto-scale, ellipsis, uppercase) · Appearance (colour, background, padding, radius,
border, shadow, stroke) · Effects · Advanced · Image (source, fit, focus X/Y, radius, border,
shadow, replace/remove) · Shape (shape, fill, gradient from/to/angle, stroke, radius) ·
Canvas (format, background type/colour/gradient/image + fit, overlay, safe area, grid, rulers,
checkerboard).

### Workflow
Explicit save (never silent template spam), coalesced `pendingSave`, revision adoption, toasts
only after the server confirms; PNG export (current + every other format, restoring the previous
format and dirty flag); artifact storage; attach to media library; publish dialog with
destinations; version history with restore.

### Keyboard
`Ctrl/⌘+S`, `Ctrl/⌘+Z` / `Shift+Ctrl/⌘+Z`, `Ctrl/⌘+D`, `Ctrl/⌘+A`, `Ctrl/⌘+C/V`, `[`/`]`, `+`/`-`,
`Shift+1`, `Shift+0`, arrows, `Delete`, `Escape`, `F2`, `Enter`, `V`, `H`, `?` — with a help
dialog, and a guard so a keypress inside an input/textarea/select is never intercepted.

### Responsive and a11y
Breakpoints at 1400/1180/1024/900/782/600 px (panels become overlays under 900 px, the format
row stays reachable as a scrollable strip under 600 px), `dvh` heights, no horizontal overflow,
roving focus and `role`/`aria-*` on the layer list, `aria-pressed` on toggles, `aria-label` on
icon buttons, live regions for toasts and canvas meta.

---

## 4. Invariants I kept (and documented in `docs/ARCHITECTURE.md`)

1. **The plan is the only thing painted.** No second renderer, no client-side text layout.
2. **Panels read the document, the canvas reads the plan** — so the inspector shows what will be
   *saved* and the canvas shows what will be *rendered*.
3. **One plan request per committed change.** Gestures mutate layers locally, then commit once.
4. **Format scoping preserved.** Geometry writes go to `layout[activeFormat]` when the active
   format is not the base format; `writeProp()` is the only writer, and adopting a sanitised
   canvas can no longer drop the base `canvas.format`.
5. **Exports use the identical painter**, at plan resolution, after fonts and images resolve.
6. **Honest status.** Nothing says "saved", "stored" or "published" unless the request succeeded.

---

## 5. Bugs found and fixed (all covered by the new gates)

| # | Bug | Effect |
| --- | --- | --- |
| 1 | `requestPlan()` adopted the plan but never repainted | **No design ever appeared on the canvas** |
| 2 | `applyViewportTransform()` scaled the frame, not the scaler wrapper | Zoom/fit did nothing visually |
| 3 | A gesture pushed history inside `commitGeometry()` *and* at drag start | Two undo steps per drag; one plan request **per selected element** |
| 4 | Auto-height text layers carry `height: 0` in the plan | Such a layer could never be clicked, dragged or double-clicked |
| 5 | `Escape` in the inline text editor called `endTextEdit( true )` | Cancelling *committed* the text |
| 6 | Inspector rebuilt after every commit | Focus (and the caret) jumped out of the field being typed in |
| 7 | `ArtifactsController::store()` read only `data` | The studio and the block editor send `binary` → **every browser render 400** |
| 8 | Exporting another format left the editor on it | Wrong format active, and a saved design looked unsaved |
| 9 | `renderAndStore()` toasted "stored" unconditionally | A design could claim storage the server never performed |

A tenth, smaller one: image painting was asynchronous even when the image was cached, so an
export could capture a half-painted canvas — images are now painted synchronously when decoded,
and `paint()` no longer resizes a headless canvas.

---

## 6. Verification

```
cd tools/harness && node verify.mjs
```

| Gate | Result |
| --- | --- |
| PHP lint (113 files) | 0 failures |
| PHP 7.4 compatibility | pass |
| Class + service integrity | pass |
| Activation simulation | pass |
| PHP suite | **321 passed / 0 failed** (includes the 4 new artifact-payload assertions) |
| Admin screens (11) | all render, no diagnostics leaked |
| Canvas render | `out/plan-preview.png` |
| **Studio smoke** | **106 checks passed** |
| **Studio paint** | square + story painted, non-blank, opaque |

`studio-smoke.mjs` boots the *real* `studio.js` against the *real* rendered markup (jsdom,
stubbed canvas/REST/media) and drives it: boot, selection, drag, resize, marquee, context menu,
layer ordering, rename, multi-select, canvas settings, inline text, formats, shortcuts, new
design, dialogs (shortcuts, versions + restore, post picker, brand kit), export, `CPICStudio`
API, and a total network failure. It fails when a control exists but does nothing — which is
exactly how bugs 1, 3, 4, 5 and 8 were found.

`studio-paint.mjs` paints real plans through the studio's painter with `@napi-rs/canvas` and
samples pixels: not blank, opaque background, content over the background. Previews:
`tools/harness/out/studio-paint-{square,story}.png`.

Both are dev-only (`tools/harness/` never ships — `tools/build/package.sh` still builds a
clean 119-file ZIP, verified).

---

## 7. Deliberate non-changes

- `TemplateSchema`, `RenderPlanBuilder`, `Formats`, `TemplateManager`, all REST routes (except the
  additive `binary` fallback), capabilities, nonces, artifact storage, publishing and the
  Gutenberg/classic-editor integration are untouched.
- No new document fields; the inspector only exposes properties the schema already accepts.
- `window.CPICStudio` kept its `export`/`state`/`paint`/`rerender` contract (plus `version`).
- No framework, no build step, no external asset.

## 8. Known limits

- The harness cannot shape Bengali conjuncts or measure real glyphs (no browser); `studio-paint`
  proves the drawing path and metrics, not the shaping. Shaping correctness remains the browser's
  job by design, and is exercised by the existing PHP suite's plan assertions.
- `decorations`-style extras (rulers, snapping) are editor-only and never part of the export.
- Version history requires a saved design; for an unsaved one the menu explains that instead of
  showing an empty dialog.
