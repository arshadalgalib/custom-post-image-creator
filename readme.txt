=== Custom Post Image Creator ===
Contributors: cpicdev
Tags: social media, image generator, facebook, telegram, bangla, Bengali
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 6.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate template-driven social media photocards with Bengali typography, category mappings, Facebook Page publishing, Telegram publishing, and a persistent background queue.

== Description ==

The plugin uses a single server-side renderer for previews, downloads, manual sharing, bulk ZIPs, and automatic generation. Template Manager controls canvas, layers, text, typography, and visibility. Persistent jobs store channel results independently and use atomic claims and bounded retries.

== Requirements ==

* WordPress 5.8 or later
* PHP 7.4 or later
* PHP Imagick and ImageMagick PNG support
* PHP cURL for social photo uploads
* Python 3 + PyGObject + PangoCairo + HarfBuzz + Cairo recommended for Bengali shaping; ImageMagick Pango is a fallback
* PHP ZipArchive for bulk ZIP downloads

== Installation ==

1. Upload the ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Configure and activate a template in Post Image Creator > Templates.
3. Configure category mappings and the default template as required.
4. Connect a Facebook Page and/or configure a Telegram bot and chat.
5. Enable automatic generation (disabled by default; the prior master switch is migrated) and desired destinations. Set a delay and retry limit.
6. Configure a real server cron to call WordPress wp-cron.php once per minute for reliable background processing.

== Bengali typography ==

The plugin includes Noto Serif Bengali, Noto Sans Bengali, and Hind Siliguri fonts. PangoCairo/HarfBuzz is recommended and registered with bundled fonts through Fontconfig. If neither PangoCairo nor ImageMagick Pango is available, Bengali image generation returns an error rather than silently rendering unshaped text. See README.md for setup and diagnostics.

== Automation ==

A single persistent job is maintained per published post. The pipeline resolves an active category/default template, creates the canonical PNG, publishes enabled destinations independently, optionally posts the Facebook first comment, and records each external identifier. Atomic job claims prevent concurrent workers from sharing the same post. Retryable errors use exponential backoff; ambiguous network outcomes are not automatically repeated.

== Facebook and Telegram ==

Facebook uses the configured Page access token and a pinned Graph API version. Meta permissions and version availability can change; inspect displayed API errors and check Meta's official version changelog before changing the integration version. Telegram sends HTML-escaped captions and canonical PNGs via Bot API `sendPhoto`.

== Changelog ==

= 6.4.0 =
* Unified manual and bulk publishing paths around the server-side template renderer; removed browser canvas uploads from share requests.
* Added server-side canonical bulk ZIP generation with per-post errors.
* Improved Bengali headline sizing, Unicode line wrapping, text-box alignment, Pango line-height/letter spacing, font resolution, and typography diagnostics.
* Bengali generation now fails explicitly when a shaping-capable engine is unavailable.
* Added independent Telegram job identifiers, delayed retry scheduling, atomic stale-job recovery, and automatic Telegram publishing.
* Prevented duplicate active jobs from being reset; admin retry/process actions no longer break active locks.
* Added repeatable database migration for independent destination state and next-attempt scheduling; pending legacy queue tasks are imported idempotently.
* Rejected malformed template JSON and bounded canvas/text configuration values.
* Preserved existing automation opt-in during migration; new installs keep automation disabled until explicitly enabled.
* Preserved all plugin data on uninstall by default, with a deliberate full-purge option in Dashboard settings.

= 6.3.0 =
* Persistent template-driven generation and Facebook queue stabilization.

== Upgrade Notice ==

= 6.4.0 =
Routine upgrade; existing plugin data is retained. Imagick is required; PangoCairo is strongly recommended for Bengali output.
