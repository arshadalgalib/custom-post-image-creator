# Custom Post Image Creator v5.2.0

Custom Post Image Creator generates social media photocards for WordPress posts and publishes them automatically to Facebook Pages and Telegram channels.

## Features

- Photocard generation from post data
  - Background
  - Featured image
  - Bangla title
  - Bangla date
  - Logo
- Facebook Page publishing (Graph API)
- Telegram publishing (Bot API)
- Auto-publish queue with WP-Cron worker
- Bulk operations for download/share
- Bangla-friendly typography support

## Full Automation Workflow

When Auto Publish is enabled, the plugin follows this queue-based flow:

1. WordPress post is published
2. Plugin saves a queue job in database option `cpic_publish_queue`
3. Cron worker runs every minute (`cpic_cron_process_queue`)
4. Worker prepares image:
   - Uses editor-captured image if available
   - Falls back to server-side generation automatically if no stored image exists
5. Worker posts to Facebook and/or Telegram
6. On transient failures, job remains queued for retry

This supports no-click automation after publish.

## cPanel Cron Setup (Recommended)

Use real server cron for reliable automation.

### 1) Disable visitor-triggered WP-Cron

Add this line to `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

### 2) Add cPanel cron job (every 1 minute)

Example command:

```bash
/usr/local/bin/php -q /home/CPANEL_USER/public_html/wp-cron.php > /dev/null 2>&1
```

Adjust PHP path and WordPress path for your server.

If PHP CLI path is not valid on your host, use HTTP fallback:

```bash
curl -fsS "https://YOUR-DOMAIN.com/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
```

## Required Plugin Settings

In plugin dashboard, ensure:

- Auto Publish = ON
- Auto Facebook = ON (if Facebook automation needed)
- Auto Telegram = ON (if Telegram automation needed)
- Valid Facebook Page access token and selected page
- Valid Telegram bot token and chat id

## Practical Reliability Notes

- The automation is fully queue-based and runs without manual share clicks.
- External APIs can still fail (expired tokens, rate limits, network issues).
- Failures are logged and retried by queue processing.
- If logs show only "Auto-publish task queued" and never "task processing started", cron is not executing reliably. This version also triggers immediate processing on publish/image-capture for due tasks, but server cron should still be configured correctly.
- Worker diagnostics now include these log lines:
  - Worker tick: queue processing started.
  - Worker tick: queue processing finished.
  - Worker tick: queue empty.

## Server Requirements

- WordPress 5.8+
- PHP 7.4+
- Imagick extension
- Python + PangoCairo optional (improves complex Bangla text shaping for server overlay path)

## File Overview

- Main bootstrap: `custom-post-image-creator.php`
- Queue worker: `includes/class-auto-publisher.php`
- Image generator: `includes/class-image-generator.php`
- Facebook API: `includes/class-facebook-api.php`
- Telegram API: `includes/class-telegram-api.php`
- Admin dashboard template: `templates/admin-dashboard.php`

## Version

Current plugin version: 5.2.0
