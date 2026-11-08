# Unicode, security, and runtime reliability — 6.5.4

**Plugin version:** 6.5.4  
**Scope:** Continued static audit of social publishing, OAuth, browser-facing admin UI, cron, and temporary-file lifecycle.

## Confirmed issues fixed

- **Bengali caption truncation:** Both Facebook and Telegram caption helpers had an over-escaped PCRE `\\X` pattern. That matched a literal backslash plus `X`, not grapheme clusters, so hosts without `mbstring` could fall back to byte-based truncation and split UTF-8 Bengali text. Corrected the PCRE pattern to `\X` so truncation respects grapheme boundaries; retained the existing multibyte fallback.
- **Retryable upgrades:** The installer now confirms all required columns across the four tables and a seeded template exist, and waits for pending legacy queue import to complete, before recording the new schema version. Failed database writes therefore remain eligible for a safe retry on a later admin request.
- **Facebook credential exposure:** OAuth page access tokens are held in a user-bound server transient instead of hidden form inputs/DOM attributes. The legacy page-list flow now returns only page IDs/names plus a short-lived server selection key, and its selector resolves the token server-side. Settings reads omit app secrets and bot/Page tokens.
- **Facebook OAuth CSRF:** OAuth start now uses a server-generated WordPress nonce URL and verifies it before issuing state/redirecting.
- **Admin UI safety:** Escaped dashboard share timestamps and job statuses; job image links now accept only HTTP(S) URLs and are HTML-escaped.
- **Timezone-correct Telegram duplicate guard:** Saved WordPress-local MySQL timestamps are parsed using the site timezone before comparing against Unix time. This avoids incorrect duplicate-window behavior on sites whose timezone differs from the PHP default.
- **Slow Jobs page:** Reading the Jobs table no longer runs up to five synchronous social API jobs. Admin fallback now asks WP-Cron to run the worker after the response.
- **Retention and cleanup:** Added daily WP-Cron maintenance for 30-day log retention, expired bulk ZIPs, aged abandoned upload chunks, and old unreferenced generated images. Cleanup unlinks generated-image symlinks rather than following their targets; chunk uploads reject a symlinked staging directory; full uninstall does not recurse through symlinked upload roots. Scheduled maintenance is cleared on deactivation/uninstall. Full uninstall also clears Telegram delivery-lock options while preserving them when the default data-preserving uninstall path is used.

## Regression checks

Added `tests/audit-safety-smoke.js` for Unicode regex escaping, OAuth nonce use, HTML/URL output handling, non-blocking Jobs reads, and daily maintenance. Existing installer, pipeline, renderer, upload, and font checks remain in place.

## Verification

- Static audit safety, installer, pipeline, and admin asset checks: **PASS**.
- Bengali font, Canvas renderer (9 Bengali/mixed-script cases), and chunked upload smoke tests: **PASS**.
- `node --check` for all JavaScript and test scripts: **PASS**.
- PHP 7.4 grammar parse with `php-parser`: **PASS**, all 25 PHP files.
- `custom-post-image-creator-6.5.4.zip`: **PASS**, 60 entries; archive integrity and source synchronization verified; tests and generated evidence are excluded.
- **Runtime limitation:** PHP CLI, WordPress/database, and Chromium are unavailable. OAuth, cron scheduling, timezone behavior, real browser rendering, and Facebook/Telegram delivery were not exercised end-to-end; verify them on staging.
