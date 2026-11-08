# Reliability and installer fix — 6.5.3

**Plugin version:** 6.5.3  
**Scope:** Full static audit of installer, queue/artifact lifecycle, and automatic publishing safety.

## Confirmed defects fixed

- **Fresh-install database creation:** Removed `IF NOT EXISTS` from all four installer `CREATE TABLE` statements. WordPress `dbDelta()` in the minimum supported WordPress 5.8 parses `CREATE TABLE ([^ ]*)`; with `IF NOT EXISTS`, it mistakes `IF` for the table name and can skip creating every plugin table. Existing tables continue to be reconciled by `dbDelta`; no rows are dropped.
- **Stale-card publishing:** Before an unposted job publishes, verify the canonical browser PNG against the current post/template hashes and refresh the job's artifact metadata. If the saved artifact is stale or missing, wait for a current browser capture rather than publishing old content. If one destination already succeeded, retain the same original artifact for remaining destinations; if it is gone, fail safely rather than send a different image.
- **Job deletion and image retention:** Deleting a queue row no longer immediately unlinks its PNG, which may still be referenced by post metadata or used for manual sharing. The existing age- and reference-aware artifact pruner owns file reclamation.
- Corrected the stale-worker threshold comment (the code has always used fifteen minutes).

## Regression checks

- Added `tests/installer-schema-smoke.js` for all four dbDelta-compatible table declarations.
- Added `tests/pipeline-safety-smoke.js` for artifact validation ordering, partial-destination consistency, and safe image retention.

## Verification performed

- Installer, pipeline safety, admin asset-order tests: **PASS**.
- Bengali font rendering, Canvas renderer (9 Bengali/mixed-script cases), and chunked upload bridge smoke tests: **PASS**.
- `node --check` for all JS and test files: **PASS**.
- PHP 7.4 grammar parse with `php-parser`: **PASS**, all 25 PHP files.
- WordPress 5.8 and 6.8 `dbDelta()` source inspected; both use the incompatible-with-`IF NOT EXISTS` `CREATE TABLE ([^ ]*)` parser pattern.
- `custom-post-image-creator-6.5.3.zip`: **PASS**, 59 entries; `unzip -t` passes; version/header/stable tag are 6.5.3; packaged files match the working tree; tests and generated evidence are excluded.
- `git diff --check`: **PASS**.

## Runtime limitations

No PHP CLI, WordPress runtime/database, or Chromium is available in this workspace. Therefore a real activation/upgrade, network/editor capture, scheduled job execution, and Facebook/Telegram API delivery were not end-to-end tested. Static and mocked checks pass; stage the upgrade on a database backup before production rollout, then verify the four `wp_cpic_*` tables exist and create a test post/job.
