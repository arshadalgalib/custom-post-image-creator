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
