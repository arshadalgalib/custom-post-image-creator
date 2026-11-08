# Rendering architecture decision

**Status:** accepted for the stabilization work (2026-11-08)
**Release target:** 6.5.0
**Scope:** photocard rendering, not social API delivery.

## Decision

Use a single browser-side Canvas 2D renderer for template previews, dashboard previews, downloads, manual publishing, and bulk output. Bundle the Unicode Bengali fonts with the plugin, load them with the browser FontFace API, and use Canvas text drawing after the browser has shaped the Unicode text. The exact PNG selected/uploaded for a post is the artifact used by the queue and both social destinations; the queue must not regenerate it with a different backend.

The WordPress editor should render and upload the current card while the post is being saved (including drafts and scheduled posts). At publish time the queue consumes that stored artifact. If no current artifact is available, automatic rendering is not possible in background PHP on ordinary shared hosting: keep the job waiting briefly for the browser upload, then fail with an actionable message rather than substituting a different renderer. Manual dashboard rendering, direct-post integrations, and bulk actions must report when their required browser artifact has not been produced. Preview and final generation use the same template/data contract and renderer implementation.

Do not silently use Imagick, GD, a local font fallback, or a different engine as a substitute. An optional remote renderer is not enabled in this release: no provider, endpoint, authentication scheme, or response contract was supplied, and making one up would create a misleading/insecure integration. A provider-specific adapter can be added when a service and contract are selected.

## Alternatives evaluated

| Approach | Shared-hosting dependencies | Bengali shaping | Background automation | Decision |
|---|---|---|---|---|
| Current PHP Imagick/Pango path | Requires Imagick plus Pango-capable delegate/font configuration, neither universal on shared hosting | Can shape Bengali when correctly built/configured, but the current target host has no such engine and the plugin returns an error | Yes, when the shaping stack exists | Rejected as mandatory/default; retain no implicit fallback |
| PHP GD + Unicode-to-Bijoy conversion | GD is common, but a converter, legacy font, `mbstring`/encoding assumptions, and correct text measurement/reordering must also be shipped | Conversion alone does not guarantee correct conjuncts, reph, vowel signs, or mixed-script layout. Candidate converter found in research has conflicting license declarations and has not passed output/coverage review | Yes, after a correct converter is proven | Rejected for this release; do not ship unverified converter/font code |
| Browser Canvas 2D with bundled Unicode fonts | Available in the admin/editor browser; no server package installation | Browser text shaping handles Unicode complex scripts; a Skia Canvas smoke PNG using the bundled Noto Serif Bengali font was generated and visually inspected | Browser-assisted capture/upload is possible; WP-Cron alone has no browser and cannot render | **Selected**, with the automation boundary and missing-artifact behavior documented above |
| Configurable remote renderer | Depends on a chosen service, credentials, network policy, and protocol | Potentially strong, provider-dependent | Yes | Deferred until a real service/API contract is named; no fabricated endpoint or credentials |

## Evidence and limits

- Generated and visually inspected `test-artifacts/bengali-canvas-smoke-test.png` with `@napi-rs/canvas` (Skia) and the bundled `fonts/NotoSerifBengali-Regular.ttf`. The sample includes Unicode Bengali prose, conjunct-heavy words (`ক্ষুদ্র`, `শিক্ষার্থীদের`, `বিশ্ববিদ্যালয়ে`), mixed Bengali/English, Bengali and ASCII numerals, punctuation, and a long headline. The PNG shows rendered glyphs and visually coherent vowel placement in this smoke test.
- This is a Skia Canvas implementation smoke test, **not** a WordPress-browser integration test. No PHP CLI, WordPress test install, or Chromium is available in the current environment. Browser FontFace loading, cross-origin image access, editor lifecycle timing, scheduled-post capture, and upload/queue integration remain to be tested on a real WordPress site and supported browsers.
- Canvas text APIs do not expose paragraph shaping controls equivalent to a professional desktop typesetter. Long headlines need explicit wrapping, font-size fitting, and clipping checks; output should be reviewed on the target browsers before claiming production typography fidelity.

## Consequences

- Plugin operation no longer depends on Pango, Python, HarfBuzz, ImageMagick Pango, or a Bijoy legacy font for Bengali output.
- The browser is a necessary participant in creating a new canonical image. A cron-only or external API publication with no editor-captured artifact cannot be promised to render without an explicitly configured server renderer.
- Rendering metadata should identify the renderer, input hash, template version/hash, and generated time. Uploads must be validated as bounded PNG files and associated with an authorized post/job.
- Existing templates/options/jobs must be migrated repeatably and without destructive resets. Previously generated images remain usable; they are not regenerated merely because the plugin upgrades.
