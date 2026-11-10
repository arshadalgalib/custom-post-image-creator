=== Custom Post Image Creator ===
Contributors: cpicdev
Tags: social media, image generator, facebook, telegram, bangla, bengali
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 7.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design social images for WordPress posts with correct Bengali typography, then publish them to Facebook Pages and Telegram channels with per-channel delivery tracking.

== Description ==

**Custom Post Image Creator** turns your posts into finished social images and publishes them — without ever producing broken Bengali text.

Most image tools draw Bengali text in PHP, where conjuncts and vowel signs break apart. This plugin does the opposite: the design is described once, then painted by the browser, which is the only engine on a typical web server that shapes Bengali correctly. Scheduled publishing is honest about this: if an image needs a browser and no editor is present, you are told, instead of silently posting a visually broken image.

= Why this version is different =

* **One design description, one result.** What you see in the studio and what gets stored come from the same render plan, so the preview cannot drift from the output.
* **Complex-script aware.** The plugin knows which renderers can shape Bengali and refuses to pretend otherwise.
* **Per-channel publishing.** Each Facebook Page and Telegram channel is a separate account with its own credentials, status and delivery history. One channel failing never rolls back another.
* **No duplicate posts.** Every delivery carries an idempotency key. A retry after an ambiguous network failure asks for confirmation rather than risking a double post.
* **Secrets stay secret.** Credentials are encrypted at rest, never returned to the browser, and never written to logs or diagnostics.
* **No invented numbers.** Analytics separates what this site measured from what a platform reported, and shows "not fetched yet" instead of zero.

= Features =

* **Twelve starter designs** for Bengali news media, newspapers, magazines, corporate notices, education, Islamic quotes, political campaigns, events, announcements, breaking news and quote cards.
* **Design studio** with a live canvas, layer manager, drag-to-move, resize and rotate handles, rulers, grid, safe-area guides, snapping, undo/redo, version history, a context-sensitive inspector and keyboard shortcuts. Every control works and every save is confirmed by the server before it is reported as saved.
* **Multiple formats** — square (1080×1080), portrait (1080×1350), story (1080×1920), landscape (1200×675) and wide. Layouts re-anchor per format instead of being squashed.
* **Brand kits** — colours, logos and Bengali type that designs inherit and can override.
* **Template library** with revisions, restore, duplicate, favourites, search and JSON import/export.
* **Scheduling calendar** and campaigns that group posts under one design and track what actually published.
* **Queue** with retries, exponential backoff, stale-job recovery and a manual "process now" button.
* **Diagnostics** that report what this installation can and cannot do, with a repair action that only touches schema and locks.
* **Optional AI assistance** for headline, hashtag and alt-text suggestions — off by default, opt-in per site, always flagged for review, never applied automatically.

= Requirements =

* WordPress 5.8 or later
* PHP 7.4 or later
* The OpenSSL PHP extension (for storing social account credentials)
* A Facebook App and Page access token, and/or a Telegram bot token — only needed if you publish

Bundled Bengali fonts (Noto Sans Bengali, Noto Serif Bengali, Hind Siliguri) are included in the plugin; no external font CDN is contacted.

= A note on automatic publishing and Bengali =

Your server cannot shape Bengali text. That is a property of PHP image libraries, not of this plugin. So:

* Images you generate while signed in look correct, always.
* Scheduled or automatic publishing of Bengali images needs a browser to have rendered them first (open the post, or the studio, and save once).
* When that has not happened, the delivery is held with a clear reason instead of publishing broken text.

Latin-only designs can be generated fully server-side.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP from Plugins → Add New.
2. Activate the plugin. The database tables are created on activation.
3. Open **Post Images → Design Studio** and save a design.
4. Open **Post Images → Social Accounts** to connect a Facebook Page or a Telegram channel.
5. Optional: **Settings → Automation** to enable automatic publishing (off by default).

== Frequently Asked Questions ==

= Does this work with the block editor and the classic editor? =

Yes. The block editor gets a "Social image" sidebar panel; the classic editor gets a metabox. Both link to the full studio.

= Will it post automatically when I publish? =

Only if you turn that on: there is a master switch and a per-platform switch in Settings → Automation. Both are off by default.

= What happens if Facebook times out? =

The delivery is marked as ambiguous, not retried automatically, and it asks you to confirm before retrying, because a timeout does not prove nothing was posted.

= Can I use my own Bengali font? =

Yes — drop it in the `fonts/` folder and add it through the `cpic/fonts` filter. The studio loads it through CSS `@font-face`.

= Is anything sent to a third party? =

Only the requests needed to publish to the platforms you connected. AI assistance is off unless you enable it and enter your own provider key.

== Screenshots ==

1. The design studio with the layer list and property inspector.
2. The template library with starter designs.
3. Brand kits.
4. The publishing calendar.
5. Diagnostics.

== Changelog ==

= 7.1.0 =
* New: the design studio is a full editor — an app-style header (inline design name, save status, undo/redo, preview, save, export, publish, more actions), a three-tab left sidebar (Design / Elements / Layers), a zoomable canvas with rulers, grid, safe area, guides and snapping, and a context-sensitive inspector.
* New: layer manager with drag-to-reorder, rename (double-click or F2), visibility, lock, multi-select, duplicate, delete and bring-forward/send-backward/front/back.
* New: 8 resize handles plus a rotation handle, arrow-key nudging, aspect-ratio and min/max resizing, smart alignment guides, marquee selection, Ctrl/⌘+wheel zoom, space-drag pan, pinch zoom and a right-click context menu.
* New: rich text controls (family, weight, style, size, colour, alignment, vertical alignment, line height, letter spacing, max lines, auto-scale, ellipsis, uppercase, background, padding, radius, border, shadow, stroke) and inline canvas text editing.
* New: image controls (media library, replace, fit cover/contain/fill/natural, focus X/Y, opacity, rotation, radius, border, shadow), drag-and-drop upload, shapes and gradients.
* New: undo/redo with coalesced history, version history with restore, and a full keyboard shortcut set with a help dialog that never intercepts typing in a field.
* New: saves are honest — a design is only reported as saved when the request succeeded, exports are rendered by the same painter as the preview, and a failed publish says so.
* Fixed: posting a PNG to `cpic/v1/artifacts` with the `binary` field (what the studio and the block editor send) was rejected; the endpoint now accepts both `data` and `binary`.
* Added: two editor gates to the verification harness. `studio-smoke.mjs` boots the real studio script against the real markup and drives it (79 checks); `studio-paint.mjs` paints real plans through the studio's painter and inspects the pixels.

= 7.0.5 =
* Added: Facebook OAuth popup on Social Accounts — click Connect with Facebook, authorize on Facebook, choose Pages; tokens are exchanged and stored automatically, no manual copy-paste.
* Fixed: activation notice recursion (`load_failures` called itself); destination status now honors all valid values.
* Added: 10-minute state and 15-minute picker transients for OAuth, with admin-post handlers and REST endpoints on `cpic_manage_accounts`.

= 7.0.4 =
* Fixed: the failure notice could itself throw. It called `Plugin::instance()`, which builds the plugin if construction had failed, so the notice died in exactly the situation it was meant to report. It now uses `Plugin::loaded()`, which never builds anything.
* Fixed: an activation request killed by a host time limit left the plugin half-installed - no tables, no capabilities - which surfaces as unrelated errors rather than an activation failure. Activation now records what it did not finish, and the remaining steps complete automatically on the next admin page load.
* Added: `cpic-check.php` now reports the MySQL/MariaDB version, whether `CURRENT_TIMESTAMP` is supported, and whether a previous activation was interrupted.

= 7.0.3 =
* Fixed (root cause): the activation hook is now registered *before* any module boots. WordPress includes a plugin from inside an admin request, after `plugins_loaded` has already fired, so anything a module did at that moment could throw and take the activation hook down with it. That is the exact mechanism behind "could not be activated because it triggered a fatal error".
* Added: if a module still fails to load, the failure is captured by module id and the plugin continues. `load_failures()` and `booted_cleanly()` report the state.
* Added: the failure notice now also appears on the Plugins list (where you land after a failed activation) and shows failures from the current request, not just stored ones.
* Added: `cpic-check.php`, a standalone diagnostic. Upload it to your WordPress root and open it in a browser: it reports the PHP version, missing files, any fatal with file and line, broken modules, failed services, database connectivity and required extensions - without needing the plugin to be active.

= 7.0.2 =
* Fixed: a module (service provider) that throws while booting no longer kills the request. Providers boot at file-include time, after the activation hook is registered, so an escaped exception was reported as "could not be activated because it triggered a fatal error" with no explanation. Each provider is now isolated, the failure is recorded by module id, and the rest of the plugin keeps working.
* Fixed: the plugin bootstrap itself is wrapped, so any boot-time failure is logged and stored for the admin notice instead of producing a white screen. `cpic()` and `cpic_service()` are guarded, and requiring the entry file more than once is safe.
* Added: `cpic_activation_errors` is now shown on every plugin screen, naming the module or step, the message, the file and line, and the PHP version.
* Added: `tools/harness/php-compat.php` scans for syntax and functions newer than the declared PHP 7.4 floor (this sandbox only has 8.0-8.4, so the ordinary lint cannot see them).
* Added: activation is verified on PHP 8.0, 8.1, 8.2, 8.3 and 8.4 via `CPIC_PHP_VERSION`, and against a hostile host where a module throws.

= 7.0.1 =
* Fixed: activating the plugin could trigger a fatal error. `PublishResult` lived in `src/Publishing/Providers/` while declaring `namespace CPIC\Publishing`, so the autoloader could never find it; it now lives in `src/Publishing/`.
* Fixed: the five 6.x compatibility shims (`CPIC_Template_Manager`, `CPIC_Image_Generator`, `CPIC_Facebook_Poster`, `CPIC_Telegram_Poster`, `CPIC_Settings`) were declared in one `Adapters.php` file, so none of them could be autoloaded. Each is now its own file with a shared `bootstrap.php`.
* Fixed: activation no longer aborts on a single failed step. Each step is isolated and a failure is recorded and shown as an admin notice naming the step, the error and the PHP version.
* Removed: the 6.x `includes/`, `js/` and `css/` directories and their templates no longer ship. They were unreachable dead code (one of them still shelled out to `exec( 'which python3' )`).
* Fixed: plugin constants are guarded, so a duplicate load cannot emit "Constant already defined" warnings during activation.
* Added: `tools/harness/verify.mjs` plus class-integrity and activation-simulation checks (312 assertions). `tools/build/package.sh` now refuses to build if a PHP file's namespace does not match its directory.

= 7.0.0 =
* Complete rewrite. New architecture (service container, repositories, REST API under `cpic/v1`), 16 database tables, and a migration path from 5.x/6.x data.
* Design studio: canvas renderer driven by the server's render plan, layer list, inspector, drag-to-move, keyboard-accessible selection.
* Twelve starter designs and a full design schema with per-format layouts.
* Multi-account publishing: several Facebook Pages and Telegram channels, each with its own status, credentials and delivery history.
* Idempotent deliveries with explicit retry, cancel and undo, plus duplicate-risk protection.
* Queue and scheduler rebuilt: claimed jobs, backoff, stale recovery, cron health reporting.
* Campaigns, publishing calendar and honest analytics.
* Brand kits and template revisions with restore, duplicate and JSON import/export.
* Diagnostics with a real repair action and credential-presence reporting.
* Secrets are encrypted at rest and fail closed when the PHP build cannot encrypt.
* Admin screens state what they cannot do (for example, background rendering of Bengali) instead of failing silently.
* 312 automated PHP assertions run in CI without a PHP binary, plus a harness that renders every admin screen and a real design to PNG.

= 6.x and earlier =
See the previous readme for the 5.x/6.x history. Legacy classes (`CPIC_Template_Manager`, `CPIC_Image_Generator`, `CPIC_Facebook_Poster`, `CPIC_Telegram_Poster`, `CPIC_Settings`) remain loadable through compatibility adapters, and existing settings and credentials are imported once on upgrade.

== Upgrade Notice ==

= 7.1.0 =
The Design Studio is now a full editor: layer manager, resize/rotate handles, rulers, grid, safe-area guides, snapping, undo/redo, version history and keyboard shortcuts. Existing designs, templates and per-format layouts open unchanged; no database migration is needed.

= 7.0.5 =
Facebook Login: connect Pages with a popup instead of pasting tokens. Configure your App ID and Secret in Settings → Social, add the shown redirect URI to the app, then click Connect with Facebook on Social Accounts.

= 7.0.4 =
Hardens activation against hosts that kill the request part-way, and stops the failure notice throwing in the one situation it exists for.

= 7.0.3 =
Fixes the root cause of the activation fatal error: the activation hook is registered before modules load, and any module failure is captured instead of killing the request. Includes `cpic-check.php` for diagnosing stubborn installs.

= 7.0.2 =
Activation hardening: a single failing module can no longer abort activation, and any cause is reported on every plugin screen. Install over 7.0.x; no data changes are needed.

= 7.0.1 =
Fixes an autoloading fatal error. If 7.0.0 refused to activate, install 7.0.1 or later over it; no data changes are needed.

= 7.0.0 =
Major release with a new database schema and a new studio. Your existing templates, settings and single-account Facebook/Telegram credentials are imported automatically on the first admin page load. Automatic publishing stays off until you enable it.
