# Architecture

## Layers

```
Bootstrap  custom-post-image-creator.php
             └── CPIC\Core\Plugin::instance()  (singleton)
                   ├── Container (id → factory)
                   ├── ModuleManager (ordered boot)
                   └── ServiceProviders
```

`Plugin` boots a fixed, ordered list of modules; each module registers its
services and, when relevant, its hooks. Services are resolved lazily by id, so a
front-end request never constructs admin objects and vice versa.

| Layer | Responsibility | Notable services |
| --- | --- | --- |
| Core | container, events, modules | `events`, `logger` |
| Security | settings schema, caps, nonces, encryption | `settings`, `capabilities`, `encryption` |
| Database | schema, migrations, repositories | `migrator`, `db`, `templates`, `brands`, `artifacts`, `destinations`, `deliveries`, `jobs_repo`, `audit`, `metrics` |
| Templates | design schema, templates, starters, import/export | `template_schema`, `template_manager`, `starter_library`, `template_importer` |
| Rendering | plan, formats, fonts, renderers, artifacts | `render_plan`, `renderers`, `fonts`, `artifact_manager`, `render` |
| Publishing | accounts, publishers, deliveries, captions | `accounts`, `publishers`, `delivery_manager`, `captions` |
| Automation | scheduler, queue, jobs, health | `scheduler`, `queue`, `job_runner`, `health`, `retry` |
| Campaigns | campaigns, calendar | `campaign_manager`, `calendar` |
| Analytics | internal + external metrics | `analytics`, `analytics_repo` |
| AI | optional providers | `ai` |
| Media | library, SVG screening | `media` |
| REST | `cpic/v1` | `rest` |
| Admin | menus, screens, editor | `admin`, `editor` |

## The design document is the contract

A template stores a **document**:

```json
{
  "schema": "7.0",
  "canvas": { "format": "square", "width": 1080, "height": 1080, "safe_area": {...}, "background_type": "gradient" },
  "elements": { "headline": { "type": "text", "source": "post_title", "x": 64, "y": 670, ... } },
  "layout": { "story": { "elements": { "headline": { "y": 1191 } } } },
  "brand": { "id": 1, "overrides": {} },
  "caption": { "include_headline": 1, ... }
}
```

Rules:

1. `TemplateSchema::validate()` is the only writer of stored documents. It strips markup, checks URL schemes, clamps geometry, drops unknown element types and removes unresolved `{{tokens}}`.
2. `elements_for_format()` merges the per-format overrides; nothing else may reposition elements.
3. Element ids are stable keys — never re-indexed (this was a real bug: an unnecessary `usort` renumbered them).

## Render pipeline

```
document + post + format
   → RenderPlanBuilder::build()   resolves dynamic text, brand, conditions, fonts
   → plan { version, format, width, height, canvas, layers[], fonts[], font_assets[], safe_area }
   → renderer
        browser  (complex scripts ✓, background ✗)  ← studio / editor
        gd       (complex scripts ✗, background ✓)  ← latin-only fallback
        custom   (via the cpic/renderers filter)
   → ArtifactManager::store_*()  writes uploads/cpic-artifacts/, records a row,
                                 optionally attaches to the media library
```

`RenderSignature::compute()` hashes the document + post content + format so an
artifact can be proven current instead of assumed current.

## The studio

`templates/admin/studio.php` + `resources/js/studio.js` + `resources/css/studio.css`
are one screen: an app shell (header, three-column body) whose only job is to
edit a document and paint its plan.

```
#cpic-studio-state (JSON)  ← boot payload: document, template, posts, fonts, formats, can
        │
        ▼
   studio.js boot()
     ├── document model + history (snapshot per edit, coalesced by key)
     ├── POST render/plan ──────────────► RenderPlanBuilder (server truth)
     │        └── paint(plan)            same painter as the export
     ├── overlay canvas                  handles, guides, marquee, grid, safe area
     └── panels / inspector              built from the document, never from the plan
```

Rules that keep the editor honest:

1. **The plan is the only thing painted.** The studio never re-implements text
   layout, font fallback or image fitting; it paints exactly what the server
   would hand to a renderer. While a field is being edited the *local* document
   is folded into the last plan by element id (`repaintPreview(document)`), so
   typing feels instant and the server's ordering, sanitising and dropped-layer
   decisions still win.
2. **Panels read the document, the canvas reads the plan.** The layer list and
   inspector show what will be *saved*; the canvas shows what will be *rendered*.
3. **One plan request per committed change.** A drag, a resize or a rotate
   mutates layers locally for the preview, then commits once on `pointerup`
   (`commitGeometry(…, { gesture: true })` + `finishGesture()`), pushing one
   history entry and firing one request.
4. **Format scoping is preserved.** Geometry edits write to
   `layout[activeFormat]` when the active format is not the document's base
   format, so switching formats never overwrites another format's layout
   (`writeProp()` is the only writer).
5. **Publishing does not use the painter.** The studio POSTs the *document* to
   `/render/document`, which builds a plan and rasterises it on the server, so a
   hand-published image and a cron-published one are the same code path.
   `renderPng()` still paints into an offscreen canvas (`els.headless`) for
   local previews and downloads, awaiting fonts and images first so a PNG can
   never capture a half-painted frame.
6. **History is document snapshots**, coalesced by key inside a 1.2 s window;
   `Escape` in an inline text editor restores the pre-edit text rather than
   committing it.

`window.CPICStudio = { export, paint, state, rerender, version }` is the block
editor's entry point: `export(document, { postId, format, brandId })` returns a
PNG data URL rendered by the same code path.

Verification: `tools/harness/studio-smoke.mjs` boots this script against the
real rendered markup in a DOM and drives it (selection, drag, resize, marquee,
ordering, rename, inline text, formats, shortcuts, save, new design, export,
network failure); `tools/harness/studio-paint.mjs` paints real plans through
the studio's painter with a real Canvas backend and inspects the pixels.

## Publishing pipeline

```
publish_post(post_id, args)
   → DeliveryManager creates a run + one delivery per destination
        delivery key: run{n}-dst{n}-post{n}   ← idempotency
   → dispatch_delivery( id )
        claim (atomic) → provider call → mark_published | mark_failed
   → finalise_run()
```

Invariants:

- A delivery is claimed atomically; two workers cannot publish the same row twice.
- A provider error that is *ambiguous* (network timeout, 5xx) is never retried automatically.
- `retry()` refuses with `cpic_duplicate_risk` unless the caller acknowledges.
- One channel failing leaves every other channel's result untouched.
- `undo()` marks a delivery `cancelled`; it does not delete history.

## Jobs

The queue is a claim-based job table (`cpic_jobs`): `claim_batch()` hands a job
and a token to exactly one worker, `start/complete/fail/release` are token
guarded, retries use exponential backoff, and `recover_stale()` returns jobs
whose worker died. A queue lock stops two cron runs from competing.

## Security

- Capabilities are mapped to WordPress capabilities in one place (`Capabilities::CAPS`), so a screen, REST route and row action always agree.
- Secrets: `Encryption::encrypt()` writes `cpic1:` ciphertext; when the PHP build has no OpenSSL it returns an empty string and the caller **refuses the write** rather than storing plain text.
- Credentials use a tagged base64 envelope (`cpic:…`) so ciphertext that begins with `/` cannot be mistaken for legacy plain text.
- Admin state changes go through `admin-post.php` with a nonce and a capability check; REST uses permission callbacks on every route.
- Diagnostics report credential *presence*, never values, and never server paths.

## Testing

See `tools/harness/README.md`. The suite runs the real code in php-wasm with
SQLite, and renders both every admin screen and a real design to PNG.
