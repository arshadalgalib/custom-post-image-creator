# Release engineering report — 6.5.0

**Status:** release candidate assembled locally; host integration sign-off remains outstanding.
**Plugin version:** 6.5.0
**Install package:** `custom-post-image-creator-6.5.0.zip` (repository root; build validation recorded below).

## Phase summary

1. **Repository/feature audit:** located server-only Imagick/Pango rendering in the renderer and its usage in previews, manual shares, bulk output, and the publishing worker. Found tracked Python helper/bytecode that was no longer appropriate for the shared-hosting release.
2. **Capabilities:** local tool environment lacks PHP CLI, Imagick, Chromium, and Pango. This is not a probe of the user's actual hosting account. No plugin or hosting server was supplied for a live capability check.
3. **Architecture decision:** accepted `docs/architecture-decision-rendering.md`; browser Canvas 2D with bundled Unicode fonts is canonical. PHP GD+Bijoy was rejected for this release because converter licensing and real-output correctness are unresolved. Remote rendering is not configured because no real provider/API contract was named; no endpoint is invented.
4. **Renderer:** added the browser renderer, bundled font loading, text wrapping/auto sizing, image layers, post/template hashes, validated upload, and a PHP facade that reuses an existing artifact or returns a browser-required error. No silent server fallback.
5. **Templates/dashboard:** template previews now run the Canvas renderer. Dashboard previews create/store the post's canonical card and show the selected renderer. Existing template records/settings are retained.
6. **API integrations:** manual Facebook and Telegram shares require the current artifact and reuse its file. Secrets remain server-side. Existing separate destination outcomes and duplicate-safety code were retained.
7. **Queue:** automatic jobs consume the editor-created artifact. Missing images are deferred without consuming retry attempts, then fail actionably after a 15-minute wait window. Added renderer/template/input hashes and render time to job rows. Existing generated job images are not regenerated on upgrade.
8. **Bulk:** bulk actions render sequentially in the browser, upload each PNG, and ZIP those same files; bulk social actions use the same per-post file.
9. **Security/performance:** nonce plus `edit_post` checks on artifact endpoints; PNG signature/type/dimension/size validation; uploads limited to 15 MB and 64–4096 px; generated-path, URL, and SHA-256 checks; stale unreferenced generated files and old ZIPs pruned conservatively. Secret tokens are not localized.
10. **Regression evidence:** JavaScript syntax checks, PHP 7.4 grammar parse, `git diff --check`, and a Skia Canvas Bengali smoke test passed locally. See below for integrations not exercised.
11. **Documentation/release:** updated hosting/setup, rendering boundary, limitations, font licenses, architecture decision, and migration/release notes. Built an installable ZIP without test harnesses, PNG evidence, Git metadata, old package, or Python bytecode.

## Changed-file report

- **New:** `includes/class-canvas-artifacts.php`; `js/canvas-renderer.js`; `js/cpic-canvas.js`; `tests/canvas-renderer-smoke.js`; `docs/architecture-decision-rendering.md`; this release report; three font OFL notices; `test-artifacts/bengali-canvas-renderer.png`.
- **Rendering/admin:** `includes/class-renderer.php` replaced the synchronous Pango/Imagick implementation with an artifact-only compatibility facade; `includes/class-image-generator.php` now documents/reuses browser artifacts; `includes/class-admin-ui.php`, `js/templates.js`, `js/cpic-script.js`, `templates/admin-dashboard.php`, and `templates/admin-setup-guide.php` switched previews and flows to Canvas.
- **Publishing/queue:** `includes/class-facebook-api.php`, `includes/class-telegram-api.php`, `includes/class-publisher.php`, `includes/class-auto-publisher.php`, `includes/class-job-queue.php`, `includes/class-installer.php`, `js/bulk-processing.js`, and `uninstall.php` were updated for canonical artifact reuse, queue metadata, browser-boundary behavior, and explicit full-purge cleanup.
- **Version/docs/licenses:** `custom-post-image-creator.php`, `README.md`, `readme.txt`, and `fonts/README.md`.
- **Removed:** `includes/render_text_element.py` and tracked `includes/__pycache__/render_text_element.cpython-314.pyc` / `render_text_overlay.cpython-314.pyc`.
- **Unchanged/preserved:** existing user templates, settings, category mappings, credentials, job rows, existing generated files, and destination API configuration. No source-level mass template rewrite or destructive data reset.

## Migration report

`CPIC_VERSION` and plugin header now report 6.5.0. The existing repeatable `dbDelta` upgrade adds four nullable/defaulted job fields: `renderer_id`, `input_hash`, `template_hash`, and `rendered_at`. Existing rows receive blank/default metadata; their current image paths and channel IDs remain intact. New browser artifacts are held in post meta with renderer ID, template hash, input hash, generated time, URL/path, and PNG SHA-256. A temporary browser-wait timestamp is also stored only while a job waits; it is removed when an artifact arrives. Explicit full uninstall now removes those new post-meta keys; normal uninstall/deactivation remains non-destructive. No migration requires server packages, image regeneration, or user credential edits.

## Test evidence

- PHP grammar: all 25 PHP files parsed using `php-parser` configured for PHP 7.4 — **PASS**. This is grammar/static parsing, not execution under PHP or WordPress.
- JavaScript grammar: all files under `js/` and `tests/` passed `node --check` — **PASS**.
- Canvas output: `NODE_PATH=/tmp/cpic-canvas-test/node_modules node tests/canvas-renderer-smoke.js` — **PASS**, nine Unicode Bengali/mixed-script cases; PNG dimensions 1200×1000; 50,006 bright glyph pixels detected. The actual image `test-artifacts/bengali-canvas-renderer.png` was visually inspected.
- `git diff --check` — **PASS**.
- Browser/WordPress/host test: **NOT RUN**. This environment has no PHP CLI, WordPress integration install, or Chromium. The Skia Canvas test exercises the same JS module and bundled font files, but it is not evidence of editor timing, browser FontFace/CORS behavior, or byte-identical output across real browsers.
- Facebook/Telegram credentials, production network access, real cron, and remote post deduplication: **NOT RUN**. No successful live API or production-host test is claimed.

## Hosting instructions and known limits

Use WordPress 5.8+, PHP 7.4+, current browser Canvas 2D/FontFace support, and writable uploads. PHP cURL and ZipArchive are needed only for social uploads and bulk ZIP respectively. Set hosting `upload_max_filesize` and `post_max_size` to at least 15 MB for largest PNGs (allow multipart overhead). Use same-site media assets or CORS-enabled image sources. Configure a real cron for `wp-cron.php` on low-traffic shared hosting.

The admin/editor browser must create the card before background delivery. Save scheduled posts in wp-admin after final edits. Posts published exclusively by API/import with no current PNG wait, then fail with instructions; open/save/render in wp-admin and retry. This release has no remote renderer because no provider contract was specified. Browser/OS text rasterization may differ slightly, and long headlines need visual review. The Skia smoke output is positive evidence for tested cases, not a blanket promise for every Bengali phrase, browser, or font configuration.

## Package verification

Built and verified `custom-post-image-creator-6.5.0.zip`: 53 entries, 406 KB compressed (about 1.07 MB uncompressed), one top-level `custom-post-image-creator/` directory, version 6.5.0 header/readme, Canvas renderer and bundled font licenses. `unzip -t` passed and a structure check confirmed no `.git`, test script, smoke PNG, old ZIP, Python helper, or `.pyc` files. The source-only smoke harness and PNG evidence remain separately at `tests/canvas-renderer-smoke.js` and `test-artifacts/bengali-canvas-renderer.png`.
