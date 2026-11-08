# Font reliability release — 6.5.2

**Plugin version:** 6.5.2

**Purpose:** Ensure SolaimanLipi is actually bundled and available in Template Manager, load its regular and bold browser faces explicitly, and regression-test the three requested Bengali font families.

## Font changes

- Bundle unmodified SolaimanLipi regular and bold TTF files. Source: [Oblivion97/mvn-jasper-font-solaimanlipi](https://github.com/Oblivion97/mvn-jasper-font-solaimanlipi), commit `1b4f13956c378015c9a92a1f751735fb4c209ac7`. The font's own name table identifies Solaiman Karim and Al Mamun Sumon and declares SIL Open Font License 1.0. Include the source license verbatim in `fonts/OFL-solaimanlipi.txt`; no font modification or format conversion was made. SHA-256: regular `651e5b4cab247cb5e91ea86ad21835578cade653bc850b377fc78380a1f07157`, bold `3f94d4ddbf7480787b88ccf443bb2b74f12d10040870c45b21da5742aa28831b`.
- Expose `SolaimanLipi`, `Noto Serif Bengali`, and `Hind Siliguri` in Template Manager only when their needed files are readable. Keep Noto Sans Bengali available too.
- Register SolaimanLipi regular as weight 400 and the bundled bold file as weight 700; existing spelling variants such as “Solaiman Lipi” and “Solaimani Lipi” resolve to the canonical family name.
- Keep Noto Serif Bengali and Hind Siliguri as their bundled regular faces; Canvas/browser font matching synthesizes bold when templates request it.
- Add a Skia Canvas smoke test for the three font faces and a generated comparison PNG at `test-artifacts/bengali-font-families.png` (test evidence is excluded from the install ZIP).

## Verification

- `node --check` across all `js/*.js` and `tests/*.js`: **PASS**; `git diff --check`: **PASS**.
- PHP 7.4 grammar parse with `php-parser`: **PASS**, all 25 PHP files. This is static parsing, not execution under PHP/WordPress.
- `tests/bengali-fonts-smoke.js`: **PASS**. Confirms actual bundled files are present and registered; visible Bengali glyph pixels appear in regular and bold settings for each family; measured text widths differ across all three families; SolaimanLipi regular/bold and the Noto Serif Bengali/Hind Siliguri regular faces are registered.
- Font metadata inspection: **PASS**. All three fonts contain Bengali Unicode glyph mappings and OpenType GSUB shaping tables; SolaimanLipi regular and bold identify the expected family and styles.
- The generated comparison image was visually reviewed. This is Skia output evidence, not a claim that all browser/OS rasterizers are pixel-identical.
- Browser FontFace/CORS on a real WordPress install remains untested here because this environment has no PHP CLI, WordPress site, or Chromium. In WordPress the fonts load from the plugin's same-origin `fonts/` directory; check browser network/console messages if a host CDN or cross-origin rewrite blocks font requests.
- `custom-post-image-creator-6.5.2.zip`: **PASS**, 58 entries, about 751 KB; `unzip -t` passes; version/header/stable tag are 6.5.2; SolaimanLipi regular/bold and license are included; test scripts and generated PNG evidence are excluded.
