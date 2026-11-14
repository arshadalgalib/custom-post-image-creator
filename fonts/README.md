Bundled Bengali TTFs. Every face here is a real weight — nothing is declared in
`@font-face` without a file behind it.

| File | Family | Weight |
| --- | --- | --- |
| `NotoSansBengali-Regular.ttf` | Noto Sans Bengali | 400 |
| `NotoSerifBengali-Regular.ttf` | Noto Serif Bengali | 400 |
| `HindSiliguri-Regular.ttf` | Hind Siliguri | 400 |
| `HindSiliguri-SemiBold.ttf` | Hind Siliguri | 600 |

All four are OFL (SIL Open Font License) from Google Fonts:

- https://fonts.google.com/noto/specimen/Noto+Sans+Bengali
- https://fonts.google.com/noto/specimen/Noto+Serif+Bengali
- https://fonts.google.com/specimen/Hind+Siliguri

## Where shaping happens

Images are rendered on the server. The plan is serialised to SVG with real
`<text>` elements and rasterised by a host tool — `rsvg-convert`, Inkscape, or
ImageMagick with its librsvg delegate — all of which run Pango/HarfBuzz and
therefore shape Bengali correctly. GD is *not* used for complex scripts: it
writes one glyph per code point, so conjuncts (ক্ষ, ঞ্জ) and the pre-base vowel
sign ি come out wrong.

Two consequences for these files:

1. **They must be installed where the rasteriser looks.** A rasteriser resolves
   `font-family="Noto Sans Bengali"` through the host's font configuration
   (fontconfig). Copying these files into the plugin lets the *editor* draw
   them; it does not make them visible to `rsvg-convert` inside a request.
   Verify with `fc-list | grep -i bengali`, and if they are absent, install them
   system-wide or add this directory to fontconfig.
2. **Advance widths are read from the files themselves.** The serializer uses
   `FontMetrics` to read `hmtx`/`cmap` so server-side line breaking matches the
   editor's. Without the files it falls back to an average advance, which errs
   towards breaking early.

## Why only these weights

If a design asks for a weight that has no file, the browser *synthesises* bold:
the glyphs get thicker but the advance widths do not change. The editor then
measures one width and paints another, so text can overlap or spill out of its
box — and a rasteriser would do the same.

`FontManager::bundled()` therefore declares only weights that ship, and the
studio resolves a requested weight to the nearest real one (drawing its own,
measurably-accounted fake bold only when a heavier weight is genuinely needed).
Adding a face here means adding its row to `FontManager::bundled()`; both the
studio and the SVG serializer pick the new weight up from the manifest.
