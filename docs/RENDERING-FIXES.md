# Rendering fixes — root causes and evidence

This round started from "there is a problem in rendering" and "so many problems".
Nothing here is claimed as fixed without a gate that fails when the fix is
reverted. Each finding below lists the symptom a user would see, the root cause
in the code, and the check that now guards it.

## The new gate

`tools/harness/render-doctor.mjs` is now part of `node verify.mjs`.

It paints **every bundled starter and the default template, in square and
story**, through the studio's *real* painter (`@napi-rs/canvas` behind jsdom),
with a real photograph standing in for every image layer, and then measures:

| Check | A user would see |
| --- | --- |
| `export-failed` | "Export does nothing" |
| `transparent-export` | Black/transparent holes in a published PNG |
| `flat-background` | A background colour instead of the gradient |
| `nothing-drawn` | A blank design |
| `chrome-exported` | Dashed "no image" outlines baked into a published PNG |
| `overflows-box` | Type clipped at the bottom of its element |
| `leaves-canvas` | Type running off the edge of the design |
| `line-too-wide` | A line spilling across the layout |
| `text-overlap` | Two text blocks printed on top of each other |
| `text-invisible` / `low-contrast` | Unreadable type |
| `missing-image` | A placeholder where an image belongs |
| `bold-has-no-face` | "Bold does nothing" |

Output images land in `tools/harness/out/doctor-*.png` for eyeballing, but the
gate only trusts measurements.

## Findings and fixes

### 1. Bold never rendered

**Symptom:** choosing Bold in the inspector changed nothing.

**Cause:** `FontManager::bundled()` declared a `600` weight that pointed at the
*regular* file (`HindSiliguri-Regular.ttf`) and marked it `synthetic`. Bengali
shaping then had no bold face to use, and browsers differ on whether they fake
one — so the request was silently dropped.

**Fix:** a real `HindSiliguri-SemiBold.ttf` (OFL, Google Fonts) is now bundled
and the declaration points at it. `FontManager::manifest()` additionally reports
`weights` (the weights a family really ships) so the studio never has to guess.
The painter resolves a requested weight to the nearest real one and, when a
heavier weight is genuinely requested, draws its own bold — and **measures it**,
so wrapping and fitting stay in step with what is painted.

**Guard:** `render-doctor` compares 400 vs 600 ink coverage (`6116 → 8571` ink
px, so the browser really is using the semibold face); PHP asserts the declared
weight has a file; the inspector labels simulated weights as "simulated".

### 2. An invalid colour blanked or greyed the whole design

**Symptom:** the whole canvas turned grey, or the export failed with
"Rendering failed", after typing a colour.

**Cause:** colours from the inspector went straight to `ctx.fillStyle`. An
unparseable value is *silently ignored* by canvas — the previous style stays in
place, so one bad value paints the background with whatever was set last
(measured: `#0B1220` → `rgb(133,137,144)`). Worse, `addColorStop()` *throws* on a
bad colour, which aborted the entire paint.

**Fix:** every colour now goes through `isColour()` / `applyColour()` /
`addStop()`, with a parse cache. Gradients are built by `makeGradient()`, which
returns `null` (so the base colour is kept) rather than throwing.

**Guard:** PHP suite plus the flat-background / transparent-export checks, which
run with a "real" photo behind every image and would catch a grey wash.

### 3. Gradients painted in the wrong direction (and could paint nothing)

**Symptom:** background gradients did not match the angle in the inspector; some
gradients were blank.

**Cause:** three different, all incorrect, angle conversions (one used
`angle − 90` with `cos/sin`, another used `angle` with no offset, and the canvas
`y` axis was not flipped relative to CSS). Each painter built its own gradient.

**Fix:** one `gradientLine()` / `makeGradient()` pair implementing the CSS
gradient line (clockwise from "to top", clipped to the box), used by the canvas
background, the overlay, text plates, shapes and images.

### 4. Auto-scale pushed text off the canvas

**Symptom:** the date/tag line in a design was enormous and half off the image.

**Cause:** `auto_scale` treated "no box" as "infinite room". Auto-height layers
(the date, the category tag) have `height: 0`, so the grow loop ran to
`max_font_size` — 23px type became 108px and left the canvas.

**Fix:** auto-scale only grows into a real box. Fitting also considers the
*width* of each line and the line count, not only total height, so type can no
longer overflow sideways inside a tall box.

**Guard:** `leaves-canvas`, `line-too-wide` and `overflows-box` in the doctor;
these found 9 designs broken by exactly this bug.

### 5. Long unspaced words could not wrap

**Symptom:** one long word (a URL, or a long Bengali compound) stuck out of the
design.

**Cause:** `wrapText()` never broke a word wider than its box — the `|| !current`
guard kept it on one line regardless.

**Fix:** character-level fallback breaking for words wider than the box, applied
both when a word starts a line and when it follows one.

### 6. Editor chrome was baked into exports

**Symptom:** published images had dashed outlines and grey "no image" boxes.

**Cause:** `paintPlaceholder()` drew its dashed outline unconditionally, and the
export path uses the same painter.

**Fix:** the dashed outline is editor-only (`els.headless` disables it); the
fallback fill still exports, but the export now *reports* it. `downloadPng()`
and the publish/artifact flows say "Image layer “Featured image” has no image; a
fallback was exported" instead of a cheerful "PNG downloaded".

**Guard:** `chrome-exported` and `missing-image`; a visible warning chip next to
the canvas, driven by the painter's own report.

### 7. `letterSpacing` leaked into measurement

**Symptom:** wrapped lines could be wider than measured and spill past the safe
area when letter spacing was set.

**Cause:** `applyTextFont()` set `ctx.letterSpacing` before `wrapText()`
measured.

**Fix:** measurement and drawing share one `measureLine()` helper that accounts
for both letter spacing and synthetic bold.

### 8. Shapes: lines ignored their thickness, colours unchecked

Lines stroked at `height` (0 for most rules, so `lineWidth: 1`), and shape
strokes/fills bypassed colour validation. Both now use the shared helpers, with
`stroke_width` driving rule thickness.

### 9. Images: auto-height boxes, borders clipped by their own clip path

A `natural_ratio` image with `height: 0` was clamped to 1px, and a border was
drawn inside the clip region so half of it was cut. Auto-height now derives from
the loaded image, borders are inset, and border/shadow colours are validated.

### 10. A stalled request froze the editor

**Symptom:** a spinner that never resolves, with no message.

**Cause:** `api()` had no timeout and no network-error translation; a 200 with an
unreadable body (a PHP notice, a login page) was returned as if it were data.

**Fix:** 20s abort with `timedOut` / `offline` copy, plus an explicit
`badResponse` error. The strings are translated in `Admin::strings()`.

**Guard:** `studio-smoke` asserts a stalled request aborts, that the message is
human-readable and never leaks `TypeError`/`undefined`, and that an unreadable
200 surfaces an error.

### 11. The harness itself could not grow

**Symptom:** adding one font broke *every* gate with "Maximum call stack size
reached during compilation".

**Cause:** `php-runner.mjs` wrote the entire plugin — every PHP file plus every
binary asset — in a single PHP script of heredocs, which php-wasm cannot compile
once the assets grow.

**Fix:** `buildFileWriters()` batches the payload (512KB per script) and reports
which batch failed. This is why the suite keeps working as fonts/images are
added.

## Verification

```
cd tools/harness && node verify.mjs
```

```
PHP test suite                     325 assertions, 0 failures
Studio smoke (editor JS)           106 checks, 0 failures
Studio paint (editor canvas)       STUDIO PAINT PASSED
Render doctor (every starter)      26 plans, 0 faults
                                   RENDER DOCTOR PASSED
✓ ALL VERIFICATION GATES PASSED
```

## Known, deliberate behaviour

- A design that references a post with no featured image still exports a
  neutral fallback fill — but the editor warns first, and the export toast warns
  too. Refusing to export would be worse than telling the user.
- Weight 500/700/800/900 for the Noto families are drawn with the painter's own
  bold because no such face is bundled; the inspector says so.
