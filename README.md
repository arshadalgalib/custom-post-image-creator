# Custom Post Image Creator — 7.0.1

Design social images for WordPress posts with correct Bengali typography, then publish them to Facebook Pages and Telegram channels with per-channel delivery tracking.

## The one idea behind this version

Your web server cannot shape Bengali text. PHP image libraries (GD, and Imagick without a shaping library) draw conjuncts and vowel signs as separate glyphs, which is why Bengali text in server-generated images so often looks broken.

So the plugin treats the browser as the shaping engine, and is honest about the consequences:

- A design is described once, as a **render plan**.
- The plan is painted by the browser (studio, editor, or a headless browser you add through a filter).
- What the studio shows and what is stored are produced from the same plan, so they cannot drift apart.
- When a scheduled publish needs a browser and none is available, the delivery is **held with a reason** instead of quietly publishing a broken image.

Latin-only designs can render fully server-side; the plugin reports which renderers can shape what, rather than implying a capability it does not have.

## What's in the box

| Area | What it does |
| --- | --- |
| Design studio | Live canvas, layer list, drag-to-move, property inspector, format switching |
| Templates | Twelve starters, revisions with restore, duplicate, favourites, search, JSON import/export |
| Brand kits | Colours, logos and fonts that designs inherit and may override |
| Publishing | Multiple Facebook Pages and Telegram channels, per-channel status and history |
| Scheduling | Calendar, campaigns, and a queue with backoff and stale-job recovery |
| Analytics | Measured-on-this-site numbers separated from platform-reported metrics |
| Diagnostics | Capability report, credential presence (never values), safe repair |
| AI (optional) | Headline / hashtag / alt-text suggestions, off by default, always reviewed |

## Requirements

- WordPress 5.8+
- PHP 7.4+
- OpenSSL PHP extension (for storing account credentials)

## Architecture

```
custom-post-image-creator.php   bootstrap: constants, autoloader, plugin instance
src/Core/                       container, event dispatcher, modules, service providers
src/Database/                   schema, migrator, repositories (16 tables)
src/Templates/                  design schema, template manager, starter library, importer
src/Rendering/                  render plan, formats, fonts, renderers, artifacts, signatures
src/Publishing/                 accounts, publishers (Facebook, Telegram), deliveries, captions
src/Automation/                 scheduler, queue, job runner, health monitor
src/Campaigns/                  campaigns and the publishing calendar
src/Analytics/                  honest internal + external metrics
src/AI/                         optional, opt-in provider framework
src/Media/                      media library and SVG safety screening
src/Security/                   settings schema, capabilities, nonces, encryption
src/REST/                       cpic/v1 controllers
src/Admin/                      menus, screens, editor integration
templates/admin/                admin views
resources/                      studio + admin CSS/JS, editor panel
tests/php/                      PHP suite (runs in php-wasm, no PHP binary needed)
tools/harness/                  verification harness (development only, not shipped)
```

Key contracts:

- `CPIC\` maps PSR-4 onto `src/`.
- A service is resolved from the container by id (`$plugin->service('render_plan')`).
- The **design document** is the render contract; everything else derives from it.
- Publishing reads only `cpic_artifacts` rows, and one channel's failure never affects another.
- Secrets are encrypted at rest, never sent to JavaScript, and never logged.

## Development

There is no PHP binary in the verification environment, so the suite runs the real code inside WebAssembly PHP with real SQLite:

```bash
cd tools/harness
npm install

# everything below, in order, with one exit code
node verify.mjs

# ...or one gate at a time:
node php-lint.mjs ../../                          # syntax gate
node smoke.mjs tools/harness/class-integrity.php  # every class autoloads, every service resolves
node smoke.mjs tools/harness/activation-smoke.php # fires the real activation hook from the entry file
node run-php-tests.mjs ../../                     # 312 assertions against SQLite
node render-admin-screens.mjs ../../              # 11 admin screens
node render-canvas.mjs                            # paint a real plan to a PNG
```

The integrity and activation checks exist because of a real 7.0.0 bug: a class
file lived in `Providers/` while declaring `namespace CPIC\Publishing`, which is
invisible to PSR-4. The repo suite never noticed; the archive check did.

The harness is intentionally **not** part of the plugin build.

## License

GPLv2 or later.
