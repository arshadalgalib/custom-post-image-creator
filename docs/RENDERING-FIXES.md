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

## Second pass: the publish dialog and "Rendering failed"

A screenshot from a real site showed the Publish dialog reporting
**"Rendering failed"** while the canvas painted the design perfectly and the
status line stayed on "Rendering…". Both symptoms had the same root cause class:
errors that were never the user's problem were being reported as one, and one
real error was hidden behind another.

### 12. The dialog's failure text was a masked `TypeError`

`event.currentTarget` is `null` once a click handler yields at its first
`await`. The publish action read it inside `finally`, threw a `TypeError`, and
that exception replaced the real message. The button reference is now captured
synchronously (with a DOM fallback), so `finally` cannot throw.

### 13. Publish claimed success that never happened

Publishing answers `201` as soon as a *run* is queued — the provider may still
refuse the post. The studio read that as "Published". It now reads the run's
per-delivery `results`:

| Endpoint said | Studio says |
| --- | --- |
| `results[].status = failed` | the provider's own reason (e.g. reconnect the page token) |
| no destinations selected | "Image saved — nothing was posted." |
| queued, waiting for the browser render | "Saved for publishing." |
| all published | "Published — N destination(s)" |

`studio-smoke` drives all four cases against a stubbed endpoint.

### 14. A stuck "Rendering…" spinner

Switching format, creating a design and exporting all call `requestPlan(false)`.
That path set no indicator (correct) but also never cleared one — so a single
earlier visible request left "Rendering…" on screen permanently. The indicator
is now owned and cleared by every visible request, unconditionally.

### 15. A dead server was silent

`rerender()` and the boot request used the quiet path, so a failing plan request
produced no toast at all: the canvas simply kept its last content. Quiet now
means "no spinner", not "no errors" — boot, public re-render and export report
failures.

### 16. "Rendering failed" for something that was never a render

Export threw the renderer's error when it had no plan. It now says what actually
happened and what to do ("check your connection, then reload").

### 17. A fresh checkout could not run one gate

`render-canvas.mjs` wrote into `tools/harness/out/`, which is gitignored, without
creating it — so the gate failed with `ENOENT` on a clean clone. It now creates
the directory.

### A studio → server round trip is now a gate

Each REST call was covered in isolation, so a mismatch between them only showed
up on a real site. `run-core.php` now drives the three controllers in the exact
order and with the exact payloads the studio uses — `/render/plan` →
`/artifacts` → `/publishing/publish` — including the duplicate-publish refusal.
Finding it surfaced two gaps in the test fixture itself (a destination with no
encrypted credentials, and a prefix collision that answered the publish POST with
a template response), which is the sort of thing only an end-to-end call catches.

## Third pass: the parts that never ran at all

The first two passes fixed things that *ran wrong*. A plugin-wide audit then
turned up code paths that never ran at all, which is invisible to any test that
only exercises the studio's happy path.

### Scheduled publishing never fired

`DeliveryManager::process_due()` existed, was correct, and had no caller. A run
created by "Schedule" was written to `deliveries` and then sat there forever,
while the UI reported the post as scheduled. Nothing in the job queue covers it
either: scheduled work lives in `deliveries`, the queue table holds `publish`
jobs, and only `process_due()` bridges them. The queue cron hook now drains due
deliveries first.

### A parked run was reported as Published

The publish dialog counted *deliveries* as success. A browser-only host returns
`waiting_for_artifact` rows — the run was accepted, nothing has left the site —
and the dialog said "Published — N destination(s)" with a success toast. Only a
row the provider confirmed is counted now; parked rows say the image is saved
for publishing. This is the same class of lie as the original "Rendering
failed": the UI promising something the server never claimed.

### "Saved for publishing." was a dead end

When the renderer is browser-only (no GD, no headless Chromium — the normal
case on shared hosting for Bengali text), `publish_post()` parks the run on
`waiting_for_artifact` and the studio stores the PNG a moment later. Nothing
picked that image up: `dispatch_delivery()` returned early on that status, on
every pass, forever. It now looks for a usable artifact for the post and adopts
it, and `artifact.created` dispatches the parked rows immediately so the user
sees the post leave inside the same request instead of waiting for a cron tick.

### Two clock bugs the gate for the above exposed

- `publish_post()` compared a UTC `scheduled_for` against site-time rows. On a
  UTC+6 site a run scheduled an hour ahead was "overdue" and posted at once; on
  a negative-offset site nothing was ever due. Input is converted to site time
  once (`to_site_time()`) and every comparison uses `is_future()`.
- `CalendarService::reschedule()` re-projected the picked wall clock, so a
  09:30 delivery was stored as 15:30.

### `@font-face` weight ranges must be disjoint

Each face was declared at its own weight, so a family with one real face let the
browser synthesise the rest — and two faces sharing `400 600` made the *last*
one win for everything, rendering regular text with the semibold file. Ranges are
now derived from the manifest's `weights`: each face owns the span up to the
midpoint of its neighbours, a single-face family owns `100 900`, and the preview
finally agrees with the export.

### Uninstall left live cron events behind

The removal path cleared `cpic_cron_process_queue` and friends. Nothing has
scheduled those names since the hooks were namespaced, so a site that deleted the
plugin with full cleanup kept three cron events pointing at classes that no
longer exist. The list now covers every generation, and a test derives the
current names from `Scheduler`'s own constants.

### Number settings silently became zero

Typing "abc" into Batch Size saved `0`, which stops the queue dead; Time Budget
`0` was read as "5 seconds". `bulk_set()` now reports the field and keeps the
stored value instead of coercing, and both the admin form and the REST
controller surface it.

## Fourth pass

A whole-plugin sweep with `E_ALL` on, driving every service and every admin-post
handler through real requests. The first pass had never actually run a handler:
the stubs were missing `check_admin_referer()`, so every POST died before it
touched anything.

### A parked publish was reported as "sent to its destinations"

`Admin::handle_publish_now()` set `$status = 'published'` for *any* non-`WP_Error`
result. A run parked on `waiting_for_artifact` — the normal outcome on a
browser-only host — has not sent anything, yet the queue screen printed "The post
was sent to its destinations." A run whose every dispatch failed read the same.
The handler now derives the notice from the result (`waiting_render`, an empty
`results` set, the per-delivery statuses) and the queue screen has separate
`queued` and `scheduled` notices that never claim a send.

### One status click produced two redirects

`handle_account_status()` and `handle_campaign_status()` called `back_to()` from
their error branch and then fell through to the success `back_to()`. `back_to()`
ends the request, so production never saw it — but the moment the handlers became
testable the double redirect appeared, and any future refactor that removed the
`exit` would have shown two flashes with the last one winning. All 26 `back_to()`
calls now end their handler explicitly.

### Edits that ignored the plugin's own capability

`EditorIntegration::handle_metabox_save()` gated on `edit_post` alone, so a role
holding "edit others' posts" but not `cpic_publish` could drive it, while the
sibling `handle_publish_now()` refused exactly that user. It now checks both.

### Unassigning a post answered `200 false`

`CampaignManager::unassign_post()` returned a bare `false` for three different
conditions — no such campaign, no such assignment, or a failed write — and the
REST layer wrapped that in `200`. It now mirrors `assign_post()` with
`cpic_campaign_missing` (404), `cpic_post_missing` (400) and `cpic_not_assigned`
(404), and emits `campaign.post_unassigned`.

### Delivery outcomes dropped the provider's message

`DeliveryManager::outcome()` kept only the generic `explain()` advice and threw
away the code whenever the current attempt happened to succeed, so a row that had
failed and then recovered could not say what went wrong. It now prefers the
provider's own `last_error` and falls back to the stored `error_code`.

### Test-suite gates that were quietly dead

Two existing assertions could never fail, which is how the defects above stayed
invisible:

- the round-trip suite looked for `deliveries[0]['delivery_id']`, but delivery
  rows are keyed `id` — so the whole "mark it published, then refuse a duplicate"
  block was skipped;
- that same block wrote `updated_at` to the `deliveries` table, which has no such
  column, so once it did run it failed on the write rather than on the behaviour.

Both are fixed, and `tools/harness/lib/php-runner.mjs` now refuses to reuse a
php-wasm session after a script called `exit()` — previously the next script
produced no output at all and still reported success.

### Stubs that had to grow up with the suite

`check_admin_referer()`, `language_attributes()`, `wp_die()`'s integer-status
overload, WordPress's `edit_post` → `edit_posts`/`edit_others_posts` meta-capability
mapping, and a `current_user_can()` that accepts extra arguments. Without these,
handler tests either could not run or refused for the wrong reason.

## Verification

```
cd tools/harness && node verify.mjs
```

```
PHP test suite                     418 assertions, 0 failures
Admin screens (seeded)             ALL SCREENS RENDERED
Admin screens (fresh install)      FRESH INSTALL SCREENS RENDERED
Studio smoke (editor JS)           120 checks, 0 failures
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
