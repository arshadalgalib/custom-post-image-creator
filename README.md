# Custom Post Image Creator 6.5.1

WordPress plugin for template-driven social cards, Bengali typography, category routing, Facebook Page and Telegram publishing, and a persistent background queue.

## Hosting requirements

- WordPress 5.8+ and PHP 7.4+.
- A current admin browser with Canvas 2D, FontFace, and Unicode Bengali fonts (the plugin bundles Noto Serif Bengali, Noto Sans Bengali, and Hind Siliguri).
- A writable WordPress uploads directory. Generated PNGs are stored in `uploads/cpic-generated/`; PNG uploads are sent in 256 KB chunks (15 MB total maximum) to tolerate typical shared-host request limits.
- WordPress outbound HTTP access for Facebook/Telegram. PHP cURL is optional; without it, the APIs fetch the public generated-image URL. PHP `ZipArchive` is optional and needed only for bulk ZIP downloads.

**Not required:** PHP Imagick, ImageMagick/Pango, Python, PyGObject, PangoCairo, HarfBuzz packages, or server-level font installation. No system package installation is needed on ordinary shared hosting.

## Install and configure

1. Upload `custom-post-image-creator-6.5.1.zip` using **Plugins → Add New → Upload Plugin**, then activate.
2. In **Post Image Creator → Templates**, review or create a template and choose the Bengali font, text boxes, sizes, wrapping, colors, layers, and background.
3. Configure a default template and optional category mappings.
4. Connect a Facebook Page and/or enter a Telegram bot token and chat ID. Credentials remain server-side; a blank password field does not clear a saved secret.
5. Enable automatic publishing only after testing the destination credentials and a post. Automatic generation remains off for new installations.
6. For regular background delivery, configure a real server cron to request `wp-cron.php` at least once per minute. WP-Cron is traffic-driven on many shared hosts.

## Rendering decision and usage

The single canonical renderer is Canvas 2D in the authenticated WordPress browser (`js/canvas-renderer.js`). Unicode text stays Unicode; the browser shapes Bengali using the bundled font. The same renderer/template/data contract is used for template preview, post preview, browser PNG creation, manual shares, and bulk actions. Once created, the PNG is saved to uploads and referenced by post metadata and the persistent job. Facebook, Telegram, retries, downloads, and the automatic queue use that exact artifact; PHP does not silently regenerate a different image.

### Automatic publishing boundary

The editor browser captures and uploads the card when a post is saved, including drafts/scheduled posts. Save a scheduled post in the WordPress editor after its content, category, featured image, and template are final. When the post publishes, WP-Cron reuses that captured PNG. It does not need a browser or system renderer.

A post published only by REST API, an importer, or another process without a current browser-created PNG cannot be rendered by background PHP in this release. The queue waits briefly for an editor upload and then fails with a clear instruction; it does not substitute a low-quality renderer. Open/save the post in wp-admin and retry. A provider-specific remote renderer is not configured because no service/API contract was selected; this release does not invent an endpoint or store remote credentials.

Dashboard previews, direct manual sharing, and bulk actions render in the current browser. Bulk rendering is sequential to limit memory, then the server ZIPs those already-created PNG files. Cross-origin image hosts that deny browser CORS can prevent a preview/render; use same-site WordPress media URLs for template assets.

### Layout behavior and limitations

Text elements use Canvas text metrics for word wrapping, per-element max width/line/height limits, optional headline auto-scaling, alignment, background pills, and shadows. Image elements support cover/contain/fill and opacity. Very long or unusual Bengali headlines should be inspected in the preview at the actual target browser size. Browser/OS versions can produce small differences; Canvas does not provide desktop-publishing-grade paragraph controls. The plugin surfaces font/image/upload failures rather than switching render engines.

## Publishing and queue safety

Each post has a persistent job and destination state. Facebook post/comment IDs and Telegram message IDs are stored separately so a success at one destination is not repeated when the other fails. Job claims are atomic; retries use bounded backoff. Ambiguous network outcomes are not blindly retried where that could duplicate a remote post. Manual and automatic publishing share the canonical image artifact. Tokens and app secrets are never sent to browser JavaScript.

## Security, migrations, and data retention

Browser artifact endpoints require a WordPress nonce plus `edit_post` capability. Uploads are limited to PNG, validated for signature/type, bounded to 64–4096 pixels and 15 MB, stored under the plugin-owned uploads directory, and tied to hashes of the current post/template. The job records renderer ID, input hash, template hash, and render time. Existing template/configuration/options/jobs are retained on upgrade. `dbDelta` adds independent renderer-metadata columns with safe defaults; there is no destructive reset or conversion of existing images. Existing completed jobs and images are not regenerated simply due to upgrade.

Deactivation and uninstall preserve data by default. Use the deliberate full-purge setting only if you intend to remove plugin settings, templates, mappings, logs, jobs, post metadata, and generated images.

## Validation evidence and limitations

- `tests/canvas-renderer-smoke.js` runs the same Canvas renderer module through `@napi-rs/canvas` (Skia) with the bundled Noto fonts. It exercises Bengali conjuncts, vowel signs, reph, mixed Bengali/English, Bengali and ASCII numerals, punctuation, and a long headline.
- `test-artifacts/bengali-canvas-renderer.png` is the generated PNG from that test and was visually inspected. It demonstrates this Skia Canvas output; it is **not** a real WordPress-browser integration test.
- `tests/canvas-upload-smoke.js` mocks the browser-to-WordPress bridge and verifies authenticated sequential 256 KiB chunks; `tests/admin-assets-smoke.js` guards the editor/dashboard/template data bootstrap ordering. These tests do not run PHP or WordPress.
- The current work environment has no PHP CLI, WordPress test install, or Chromium. Syntax was parsed with `php-parser` configured for PHP 7.4 and JavaScript syntax was checked with Node, but WordPress lifecycle, browser FontFace/CORS behavior, editor save timing, chunked uploads, scheduled publishing, outbound HTTP/cURL integrations, and database migrations still require staging-site testing. No successful Facebook/Telegram integration test is claimed.

Optional source-checkout renderer smoke test (run in the source repository, not from an installed ZIP):

```sh
npm install --no-save @napi-rs/canvas
node tests/canvas-renderer-smoke.js
node tests/canvas-upload-smoke.js
node tests/admin-assets-smoke.js
```

The test harness, generated evidence PNG, and test dependency are kept out of the installable plugin ZIP; they are not production requirements.

## Research summary

PHP GD plus Unicode-to-Bijoy conversion was considered but not adopted: conversion/font licensing and mapping correctness (conjuncts, reph, vowel signs, mixed scripts, and metrics) were not adequately verified, and one candidate converter had inconsistent license claims. Browser Canvas retained Unicode and produced visually inspected shaping in the Skia smoke test. Pango/Imagick can shape text when installed, but they are not universal shared-hosting dependencies. Browser-only rendering requires editor participation for background jobs; no browser process is available to WP-Cron. Remote rendering remains a future provider-specific extension.

See `docs/architecture-decision-rendering.md` for the decision record and `docs/release-6.5.1.md` for the phase/test/release checklist and migration notes.
