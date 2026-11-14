# Verification harness

Development-only. Nothing in this folder ships in the plugin ZIP.

The sandbox this plugin was built in has **no PHP binary**, so the real code is
executed inside WebAssembly PHP:

| Script | What it proves |
| --- | --- |
| `php-lint.mjs` | Every PHP file parses (uses `token_get_all(..., TOKEN_PARSE)` — fast, one process) |
| `run-php-tests.mjs` | The PHP suite runs against real SQLite: boot, schema/migration, encryption, settings, design schema, multi-format layouts, render plans, signatures, fonts, renderer guards, artifacts, publishing, deliveries, jobs, retries, accounts, captions, health, analytics, AI, REST, brands, media, legacy bridge, events |
| `render-admin-screens.mjs` | Every admin screen renders against real services with seeded content, and no PHP diagnostic leaks into the HTML |
| `render-canvas.mjs` | A real render plan is painted to a PNG by an independent canvas implementation — the only way to catch drawing bugs (e.g. a missing featured image silently erasing its layer) |
| `class-integrity.php` | Every `CPIC\...` class autoloads and every container service resolves — catches files whose namespace does not match their directory, which are invisible to PSR-4 and only appear as a runtime fatal |
| `activation-smoke.php` | Loads the real entry file from a fresh boot, fires the registered activation hook, then asserts tables, options, capabilities, cron, idempotency and failure isolation |
| `verify.mjs` | Runs all of the above in order; one command, one exit code |

## Why the plugin is inlined, not mounted

The wasm filesystem cannot see the repository, so `lib/php-runner.mjs` reads the
PHP sources and the bundled fonts and generates one script that writes them into
the VM (`/plugin`). Binary assets (`.ttf`) travel as base64.

## Commands

```bash
cd tools/harness
npm install

node verify.mjs                       # every gate below, in order

node php-lint.mjs ../../                          # syntax gate
node smoke.mjs tools/harness/class-integrity.php  # autoload + container integrity
node smoke.mjs tools/harness/activation-smoke.php # activation, from the entry file
node run-php-tests.mjs ../../                     # 312 assertions
node render-admin-screens.mjs ../../              # 11 screens
node render-canvas.mjs                            # writes out/plan-preview.png
```

All scripts exit non-zero on failure, so they can gate a release.

### Testing the shipped ZIP, not just the repo

The activation bug fixed in 7.0.1 was a *file layout* bug: a class file sat in
`Providers/` while declaring `namespace CPIC\Publishing`, so the autoloader never
found it. The repo suite could not see it, because the class happened to be
loaded by a `require` in another file during tests. Always check the archive too:

```bash
./tools/build/package.sh
mkdir -p /tmp/zipsite && unzip -q dist/custom-post-image-creator-*.zip -d /tmp/zipsite
cp -r tests /tmp/zipsite/custom-post-image-creator/tests
mkdir -p /tmp/zipsite/custom-post-image-creator/tools/harness
cp tools/harness/{class-integrity,activation-smoke}.php \
   /tmp/zipsite/custom-post-image-creator/tools/harness/
```

Then point a session at `/tmp/zipsite/custom-post-image-creator` and run the two
`smoke.mjs` checks. `tools/build/package.sh` also refuses to build when a file's
namespace does not match its directory.

## Extensions loaded

`mbstring` (Unicode/Bengali), `sqlite` (PDO), `openssl` (credential encryption),
`zlib` (PNG test fixtures). `gd` and `imagick` are unavailable — which is fine,
because the plugin must behave correctly *without* them, and the suite asserts
exactly that.
