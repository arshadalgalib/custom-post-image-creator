# Custom Post Image Creator 6.4.0

A WordPress plugin for creating template-driven social cards, with Bengali typography support, category-to-template routing, Facebook Page publishing, Telegram photo publishing, and a persistent background queue.

## Requirements

- WordPress 5.8 or later; PHP 7.4 or later.
- PHP Imagick and a working ImageMagick PNG build (required for image composition).
- Python 3 with PyGObject, PangoCairo, HarfBuzz, and Cairo is strongly recommended for reliable Bengali shaping. ImageMagick's Pango delegate is the fallback shaping engine. If neither is available, Bengali rendering is rejected instead of silently producing broken glyphs.
- `ZipArchive` for server-rendered bulk ZIP downloads.
- PHP cURL for Facebook and Telegram photo upload.

Bundled fonts are in `fonts/`. The renderer registers the bundled font directory with Fontconfig and resolves font families to known files; it does not choose an arbitrary font from filesystem order.

## Installation and setup

1. Upload and activate the plugin ZIP through **Plugins → Add New → Upload Plugin**.
2. Open **Post Image Creator → Templates**. Configure and activate a template; set a default template and optionally map categories in **Categories**.
3. Configure Facebook Page and Telegram Bot credentials in the dashboard. Tokens remain server-side.
4. Enable automatic generation (disabled by default; the prior master switch is migrated), choose destinations, and configure publishing delay and retry limit.
5. For reliable scheduled processing, configure a real cron to call WordPress `wp-cron.php` once per minute. The plugin also schedules a single-job event and has a throttled admin-request fallback.

## Rendering and publishing architecture

The server-side `CPIC_Renderer` is authoritative. Template previews, post previews, individual PNG downloads, manual Facebook/Telegram publishing, bulk ZIPs, and queue generation use the same template resolver and rendering code. The browser does not upload canvas images to a publishing endpoint.

Headline auto-scaling uses Pango's shaped layout metrics when PangoCairo is available. It searches for the largest size satisfying the configured min/max, width, line count, and optional height limits. `auto_scale` off preserves the configured font size. Bengali output requires a functioning Pango/HarfBuzz-capable renderer; a status panel and preview metadata expose engine and typography diagnostics.

## Automation and retries

A single persistent `cpic_jobs` row owns each WordPress post. Jobs track Facebook post/comment IDs and Telegram message IDs separately, so a successful destination is not repeated when the other destination fails. Claims are atomic; only claims stale for at least fifteen minutes are recoverable. Retry timing uses exponential backoff, and ambiguous network outcomes are not blindly replayed. Admin retries are available from the Jobs page.

## Troubleshooting

- **Imagick missing:** enable the PHP Imagick extension and ensure ImageMagick can read/write PNG.
- **Bengali shaping unavailable:** install Python 3, PyGObject, Pango/PangoCairo, HarfBuzz, Cairo, and Fontconfig, or install an ImageMagick build with its Pango delegate. Check the dashboard's Bangla Text Engine Status.
- **Requested font unavailable:** select one of the bundled Bengali families or install the font with Fontconfig. The renderer logs the actual fallback font.
- **No automatic jobs:** check that automatic generation is enabled and that WP-Cron/real cron is running. Inspect the Jobs page and plugin logs.
- **Facebook failures:** verify the selected Page, Page access token, app mode/permissions, and the error displayed in the dashboard. Token expiry and permissions are reported by Graph API.
- **Telegram failures:** verify the bot token, chat ID, bot membership/administrator rights, and Telegram's photo/caption restrictions. Ambiguous transport failures require checking the destination before retry. Only after confirming that no message was sent, clear the per-post lock with `wp option delete cpic_tg_publish_lock_POST_ID` (replace `POST_ID`), then use Jobs → Retry. For Facebook ambiguous outcomes, inspect the Page first; the publish/comment locks are intentionally retained to prevent duplicates and should be cleared only after verifying the corresponding remote post/comment state.
- **Bulk ZIP fails:** enable PHP ZipArchive and ensure the WordPress uploads directory is writable.

## Facebook API version

The integration remains pinned to Graph API v22.0 to avoid an untested version migration. Meta's official version changelog lists v22.0 availability as TBD and documents v26.0 as released July 29, 2026; verify API status and app permissions before changing the pinned version.

## Data and upgrades

Database updates use repeatable `dbDelta` schema reconciliation. Upgrades retain templates, mappings, options, logs, and existing jobs; new independent Telegram state, destination-snapshot, and retry scheduling columns default safely for existing rows. Pending tasks from the old option-backed queue are imported idempotently into the persistent queue with their scheduled time and destination choices; the original option is retained. Deactivation and uninstall preserve all plugin data by default. To explicitly purge settings, templates, mappings, jobs, logs, post metadata, and generated images when uninstalling, enable the full-purge checkbox in Dashboard → Automation Settings before deleting the plugin.
