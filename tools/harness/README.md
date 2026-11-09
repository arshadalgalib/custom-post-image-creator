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

## Why the plugin is inlined, not mounted

The wasm filesystem cannot see the repository, so `lib/php-runner.mjs` reads the
PHP sources and the bundled fonts and generates one script that writes them into
the VM (`/plugin`). Binary assets (`.ttf`) travel as base64.

## Commands

```bash
cd tools/harness
npm install

node php-lint.mjs ../../              # syntax gate
node run-php-tests.mjs ../../         # 282 assertions
node render-admin-screens.mjs ../../  # 11 screens
node render-canvas.mjs                # writes out/plan-preview.png
```

All scripts exit non-zero on failure, so they can gate a release.

## Extensions loaded

`mbstring` (Unicode/Bengali), `sqlite` (PDO), `openssl` (credential encryption),
`zlib` (PNG test fixtures). `gd` and `imagick` are unavailable — which is fine,
because the plugin must behave correctly *without* them, and the suite asserts
exactly that.
