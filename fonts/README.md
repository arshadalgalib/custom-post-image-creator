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

## Why only these weights

The browser is the shaping engine for Bengali. If a design asks for a weight
that has no file, the browser *synthesises* bold: the glyphs get thicker but the
advance widths do not change. The editor then measures one width and paints
another, so text can overlap or spill out of its box.

`FontManager::bundled()` therefore declares only weights that ship, and the
studio resolves a requested weight to the nearest real one (drawing its own,
measurably-accounted fake bold only when a heavier weight is genuinely needed).
Adding a face here means adding its row to `FontManager::bundled()`; the studio
picks the new weight up from the manifest with no further changes.
