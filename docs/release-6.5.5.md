# Deterministic queued delivery intent — 6.5.5

**Plugin version:** 6.5.5
**Scope:** Make the existing persistent publishing queue preserve its Facebook, Telegram, and Facebook URL-comment intent across delayed work and retries.

## Changes

- New jobs snapshot the three relevant automation settings when they are enqueued. A change to global settings no longer silently changes the delivery plan for an already queued job or a retry.
- The nullable columns let pre-6.5.5 jobs retain their legacy behavior: `NULL` means the publisher falls back to current global settings. No destructive backfill is performed.
- The Jobs screen now displays the stored destinations and comment choice; old rows with no snapshot are identified as using legacy/current settings.
- Installer `dbDelta()` reconciliation and required-column readiness checks include the comment-intent column. Legacy queue import also initializes it when importing historical automation tasks.

## Regression checks

Added `tests/job-intent-smoke.js` to guard the insert format/data alignment, schema/readiness inclusion, publisher fallback, and Jobs API/UI visibility.

## Verification

- Job intent smoke test: **PASS**.
- Full repository smoke checks and syntax parsing are recorded after execution for this release.
- **Runtime limitation:** This environment has no PHP CLI, WordPress/database runtime, or Chromium. Live database migration and scheduled/social publishing remain staging-test items.
