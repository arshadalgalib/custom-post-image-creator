# Bundled Unicode Bengali fonts

The browser renderer loads these Unicode fonts with the FontFace API. It waits for every selected face to load before painting text; the font is not converted to a legacy encoding.

- `NotoSerifBengali-Regular.ttf` — Noto Serif Bengali; Noto Project Authors, SIL Open Font License 1.1 (`OFL-notoserifbengali.txt`).
- `NotoSansBengali-Regular.ttf` — Noto Sans Bengali; Noto Project Authors, SIL Open Font License 1.1 (`OFL-notosansbengali.txt`).
- `HindSiliguri-Regular.ttf` — Hind Siliguri; Indian Type Foundry, SIL Open Font License 1.1 (`OFL-hindsiliguri.txt`).
- `SolaimanLipi.ttf` and `SolaimanLipi-Bold.ttf` — SolaimanLipi regular and bold, designed by Solaiman Karim and Al Mamun Sumon; SIL Open Font License 1.0 (`OFL-solaimanlipi.txt`). The unmodified font binaries and license are from [Oblivion97/mvn-jasper-font-solaimanlipi](https://github.com/Oblivion97/mvn-jasper-font-solaimanlipi), commit `1b4f13956c378015c9a92a1f751735fb4c209ac7`; their name table identifies both designers and the OFL 1.0.

All applicable font-license texts are included beside the binaries. Template Manager exposes only font families present in this directory. SolaimanLipi has explicit regular and bold faces; Noto Serif Bengali and Hind Siliguri use their bundled regular face, with browser-synthesized bold where a template requests it.
