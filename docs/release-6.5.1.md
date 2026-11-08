# Repair release notes — 6.5.1

**Plugin version:** 6.5.1  
**Purpose:** Correct editor/dashboard/template Canvas bootstrap, make PNG transfer more tolerant of shared-host limits, and remove PHP cURL as a hard requirement for social publishing.

## Changes

- Construct `cpicData` before editor early-return; localize it on the `cpic-canvas` handle on editor, dashboard, and template screens so the dependent renderer always boots with its config.
- Keep browser Canvas 2D and bundled Unicode Bengali fonts as the only canonical renderer. WP-Cron continues to reuse the exact uploaded PNG and never silently substitutes server rendering.
- Upload PNGs as sequential 256 KiB AJAX chunks (up to 15 MiB total), validate and assemble in a denied staging directory, and copy/check the artifact without loading the full PNG into PHP memory. Keep the legacy whole-file endpoint for compatibility.
- Use WordPress HTTP API plus a public generated-image URL when PHP cURL/CURLFile is unavailable. In that fallback, the destination API must be able to fetch the site's uploads URL. PHP cURL remains preferred for direct binary upload.
- Improve browser-side AJAX errors and font-load retry behavior; show a visible editor notice for browser PNG save success/failure and provide actionable missing-font/CORS messages.
- Bump to 6.5.1 so WordPress detects this as an update; the existing `admin_init` version check runs repeatable schema reconciliation. No destructive reset or forced artifact regeneration is added.

## Shared-hosting limits and operational boundary

Required for rendering/publishing: WordPress 5.8+, PHP 7.4+, a current admin browser with Canvas 2D and `FontFace`, writable uploads, and outbound WordPress HTTP for social APIs. A single PNG upload request is 256 KiB plus multipart/form fields; the total accepted artifact remains at most 15 MiB. PHP cURL is optional. Without it, the site must allow public retrieval of generated files by Facebook/Telegram. `ZipArchive` is optional and used only for bulk ZIP downloads. WP-Cron is traffic-driven on many hosts; configure a host cron for reliable low-traffic processing.

A browser-captured PNG is still needed before automatic publishing. Posts published only by REST/import without a current card wait and then fail actionably; this release does not add a remote renderer or a server-side text engine.

## Verification

- `node --check` across `js/*.js` and `tests/*.js`: **PASS**.
- Canvas renderer smoke through `@napi-rs/canvas`/Skia: **PASS**, nine Bengali/mixed-script cases; 50,006 bright glyph pixels detected. The generated PNG was inspected in the prior renderer validation; this is not proof of every production browser/font combination.
- Browser upload bridge mock: **PASS**, a 599,288-byte PNG was sent sequentially as 256 KiB, 256 KiB, and 75,000-byte authenticated AJAX chunks.
- Static admin asset bootstrap regression: **PASS**, verifies early shared config and localization for editor, dashboard, and templates.
- PHP 7.4 grammar parse with `php-parser`: **PASS**, all 25 PHP files.
- `git diff --check`: **PASS**.
- `custom-post-image-creator-6.5.1.zip`: **PASS**, 54 entries, about 418 KB; `unzip -t` passed; one top-level plugin directory; header/constant/stable-tag all 6.5.1; repair source and report included; tests, smoke PNG, `.git`, caches, and nested ZIPs excluded.
- A real WordPress/browser/shared-host installation, editor save lifecycle, actual low upload limits, WP-Cron, database upgrade, and Facebook/Telegram credentials are not available in this environment and are not claimed as verified.
