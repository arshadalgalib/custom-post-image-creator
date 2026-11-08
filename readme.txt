=== Custom Post Image Creator ===
Contributors: cpicdev
Tags: social media, image generator, facebook, telegram, bangla
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 6.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate custom 1080×1080 social media photocard images for WordPress posts with full Bengali typography support, Facebook Page publishing, and Telegram channel sharing.

== Description ==

**Custom Post Image Creator** is a powerful WordPress plugin that automatically generates beautiful social media photocard images for your blog posts and publishes them to Facebook Pages and Telegram channels.

= Key Features =

* **1080×1080 Photocard Generator** – Create stunning social media images with layered backgrounds, featured images, titles, dates, and logos.
* **Full Bengali (Bangla) Support** – Bangla date formatting, Bangla digit conversion, Bangla fonts (Noto Serif Bengali, Hind Siliguri, SolaimanLipi, Siyam Rupali).
* **HTML5 Canvas Preview** – Instant live preview with real-time customization.
* **Facebook Graph API v22.0** – Post photocards directly to your Facebook Page with smart captions and auto-comments with post links.
* **Telegram Bot API** – Send photocards to Telegram channels with formatted captions.
* **Auto-Publishing** – Fully automatic editor-captured photocard sharing when a post is published.
* **Bulk Operations** – Bulk ZIP download, bulk Facebook share, bulk Telegram share with progress tracking.
* **Dark Mode** – Toggle dark mode for comfortable editing.
* **Multiple Backgrounds** – Upload unlimited backgrounds with gallery management.
* **Smart Caption Generator** – Automatically generates ~50-word Bangla summaries for Facebook captions.
* **WP-Cron Queue** – Queued processing for heavy sites.
* **Queue-Based Publishing** – WP-Cron processes and shares stored photocards in the background after publish.

= Requirements =

* WordPress 5.8 or later
* PHP 7.4 or later
* Imagick PHP extension
* Facebook App credentials (for Facebook publishing)
* Telegram Bot Token (for Telegram publishing)

= Recommended Cron Setup (cPanel) =

For reliable full automation, use a real server cron (not only visitor-triggered WP-Cron):

1. Add this in `wp-config.php`:

`define('DISABLE_WP_CRON', true);`

2. In cPanel -> Cron Jobs, run every 1 minute:

`/usr/local/bin/php -q /home/CPANEL_USER/public_html/wp-cron.php > /dev/null 2>&1`

Adjust the PHP binary and WordPress path for your server.

= Full Automation Flow =

When Auto Publish is enabled:

1. Post is published in WordPress.
2. Plugin stores a job in the queue (`cpic_publish_queue`).
3. Cron worker (`cpic_cron_process_queue`) runs every minute.
4. Worker prepares image:
	- Uses editor-captured image if available.
	- Falls back to server-side photocard generation automatically if not available.
5. Worker posts to Facebook and/or Telegram automatically.
6. On temporary API/network failure, job remains in queue for retry.

== Installation ==

1. Upload the `custom-post-image-creator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu.
3. Navigate to **Post Image Creator** in the admin menu.
4. Configure your Facebook App, Telegram Bot, and image settings.
5. Place Bengali font files (`.ttf`) in the `/fonts/` directory.

== Frequently Asked Questions ==

= Where do I get Bengali fonts? =

Download Noto Serif Bengali from Google Fonts and Hind Siliguri from Google Fonts. Place the `.ttf` files in the plugin's `/fonts/` directory.

= Do I need Imagick? =

Imagick is required for image compositing. Bangla-safe full auto-publishing works by capturing the photocard in the WordPress editor, then sharing automatically through the WP-Cron queue.

= How do I connect Facebook? =

1. Create a Facebook App at developers.facebook.com.
2. Enter your App ID and Secret in the plugin settings.
3. Generate a User Access Token with `pages_manage_posts` and `pages_read_engagement` permissions.
4. Click "Get Pages" and select your page.

== Changelog ==

= 5.2.0 =
* Timezone-safe Bangla date rendering (wp_date instead of date()).
* Bengali-safe caption word counting (str_word_count only handled ASCII).
* Telegram HTML caption escaping so titles with & < > no longer break sending.
* cURL availability checks for Facebook and Telegram sharing.
* Temp image files are now always cleaned up after sharing.
* Removed external CDN dependencies (JSZip, FileSaver, jQuery UI CSS).
* Native HTML5 date pickers replace jQuery UI datepicker.
* Native ZIP downloader replaces JSZip + FileSaver.
* App Secret / Bot Token are no longer echoed back to the browser.
* Cron scheduling moved to init for reliable registration.
* Auto-publish share copies are cleaned up after each network post.
* Added uninstall.php for complete data removal on delete.
* Restructured and modernized codebase with reduced warnings.

= 5.0 =
* Initial release with full feature set.
* Smart Facebook caption generator.
* Telegram integration.
* Auto-publishing with editor-rendered Bangla image capture and WP-Cron queue.
* Bengali localization system.
* Dark mode.
* Bulk operations with progress tracking.

== Upgrade Notice ==

= 5.2.0 =
Maintenance and stability release. Fixes Bengali caption issues, Telegram caption failures, credential exposure in the admin UI, file cleanup leaks, and removes all external CDN dependencies.
