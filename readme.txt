=== Custom Post Image Creator ===
Contributors: cpicdev
Tags: social media, image generator, facebook, telegram, bangla, Bengali
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 6.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create template-driven social photocards with Unicode Bengali text, category mappings, Facebook Page and Telegram publishing, and a persistent background queue.

== Description ==

The plugin uses one browser Canvas 2D renderer and one canonical PNG per post. Template previews, dashboard previews, manual shares, bulk ZIPs, and the automatic queue use the same template and rendering contract. The exact generated PNG is reused by Facebook, Telegram, and retries.

Bengali shaping uses bundled Unicode fonts and the browser's Canvas text engine. The server does not need Imagick, ImageMagick/Pango, Python, PangoCairo, or HarfBuzz packages. The WordPress editor browser captures the image when the post is saved; WP-Cron later reuses it. Cron alone cannot render a post that has no browser-created image. REST/import-only publishing without an artifact is reported as a clear queue failure rather than switching renderers.

== Requirements ==

* WordPress 5.8 or later; PHP 7.4 or later
* Current admin browser with Canvas 2D and FontFace support
* Writable WordPress uploads directory (PNG transfers use 256 KB chunks; 15 MB total maximum)
* WordPress outbound HTTP access for Facebook/Telegram; PHP cURL is optional (without it, the remote API fetches the public generated-image URL)
* PHP ZipArchive is optional and needed only for bulk ZIP downloads

== Installation ==

1. Upload `custom-post-image-creator-6.5.1.zip` via Plugins > Add New > Upload Plugin and activate.
2. Configure a template, default template, and optional category mappings.
3. Connect Facebook and/or configure the Telegram bot and channel.
4. Enable automatic generation only after testing. Save scheduled posts in the WordPress editor so the browser captures their canonical image.
5. Configure a real cron request to wp-cron.php once per minute for reliable background work.

== Bengali rendering ==

Includes Noto Serif Bengali, Noto Sans Bengali, and Hind Siliguri Unicode fonts. The Canvas renderer supports text alignment, wrapping, headline auto-sizing, max lines/height, image fitting, and layered template elements. Review long Bengali headlines in the target browser. Browser/OS implementations can differ slightly. Cross-origin images must allow CORS; same-site WordPress media is recommended.

== Automation ==

Persistent per-post jobs record each destination independently, use atomic claims, bounded retries, and renderer/input/template hashes. A current browser-captured PNG is required before background publication. Jobs wait briefly for the editor upload and then fail actionably if it does not arrive. Existing jobs, settings, templates, mappings, and images are retained on upgrade; schema changes use repeatable dbDelta reconciliation. Uninstall preserves data by default.

== Security ==

Artifact endpoints require a WordPress nonce and the current user's edit_post permission. The upload accepts bounded, validated PNG data only and stores it within the plugin-owned uploads subdirectory. Social credentials remain server-side.

== Changelog ==

= 6.5.1 =
* Fixed Canvas bootstrap data ordering on the editor, dashboard, and template screens.
* Added chunked browser PNG uploads and file-based validation/storage to tolerate modest shared-host request and memory limits.
* Made Facebook and Telegram publishing work without the PHP cURL extension when the generated image is publicly fetchable; WordPress outbound HTTP is still required.
* Preserved the canonical browser-rendered PNG for scheduled jobs and made request errors more actionable.

= 6.5.0 =
* Selected browser Canvas 2D with bundled Unicode Bengali fonts as the canonical rendering path; removed server-side Pango/Imagick/Python requirements and silent renderer fallback.
* Added shared Canvas renderer for template preview, dashboard preview, editor capture, manual sharing, and bulk output.
* Added authenticated, bounded PNG artifact upload, current post/template hash validation, path/integrity checks, and per-job renderer/input/template metadata.
* Reused one PNG across Facebook, Telegram, retries, and bulk ZIPs; automatic jobs wait for the editor-created image and fail clearly when unavailable.
* Added Skia Canvas Bengali smoke test and inspected PNG evidence at `test-artifacts/bengali-canvas-renderer.png`.
* Updated setup, hosting, automation-boundary, migration, and renderer decision documentation.

= 6.4.0 =
* Added persistent per-post jobs, separate Facebook/Telegram destination state, bounded retries, and idempotent upgrade logic.
* Improved template-driven rendering, job operations, and bulk publishing. (6.4 server renderer is retained here as historical release notes; 6.5 replaces that engine.)

== Upgrade Notice ==

= 6.5.1 =
Existing plugin data is retained. No server-side text shaping packages are required. Save posts in the WordPress editor to capture the browser-rendered PNG needed by automatic publishing.
