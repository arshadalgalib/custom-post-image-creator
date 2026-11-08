#!/usr/bin/env python3
"""Render a single template text element with PangoCairo.

Pango/HarfBuzz performs proper complex-script shaping (Bangla conjuncts,
vowel reordering) which ImageMagick's annotateImage only does when built
with libraqm. The plugin renders text through this script when Python +
PangoCairo are available and falls back to annotateImage otherwise.

The output PNG surface is sized to the element's box (text layout +
horizontal/vertical padding). PHP composites the PNG at its computed
position and reads its size from the PNG header for alignment.

Input: JSON file path argv[1]
Output: PNG path argv[2]
"""

import json
import math
import sys

import gi

gi.require_version("Pango", "1.0")
gi.require_version("PangoCairo", "1.0")

from gi.repository import Pango, PangoCairo  # noqa: E402
import cairo  # noqa: E402


def parse_color(value, default=(1.0, 1.0, 1.0, 1.0)):
    """Parse hex (#RGB/#RGBA/#RRGGBB/#RRGGBBAA) or rgba() to 0..1 float RGBA."""
    if not value:
        return default
    s = str(value).strip()

    if s.startswith("#"):
        h = s[1:]
        if len(h) in (3, 4):
            h = "".join(c * 2 for c in h)
        if len(h) in (6, 8) and all(c in "0123456789abcdefABCDEF" for c in h):
            r = int(h[0:2], 16)
            g = int(h[2:4], 16)
            b = int(h[4:6], 16)
            a = int(h[6:8], 16) if len(h) == 8 else 255
            return (r / 255.0, g / 255.0, b / 255.0, a / 255.0)

    if s.startswith("rgba(") and s.endswith(")"):
        parts = [p.strip() for p in s[5:-1].split(",")]
        if len(parts) == 4:
            r, g, b, a = [float(p) for p in parts]
            return (
                max(0.0, min(r / 255.0, 1.0)),
                max(0.0, min(g / 255.0, 1.0)),
                max(0.0, min(b / 255.0, 1.0)),
                max(0.0, min(a, 1.0)),
            )

    return default


def family_for(name):
    mapping = {
        "Noto Serif Bengali": "Noto Serif Bengali",
        "Noto Sans Bengali": "Noto Sans Bengali",
        "Hind Siliguri": "Hind Siliguri",
        "SolaimanLipi": "SolaimanLipi",
        "Siyam Rupali": "Siyam Rupali",
    }
    return mapping.get(name) or "Noto Serif Bengali"


def make_layout(ctx, text, family, size_px, max_width=None, bold=False, line_height=1.3, align=None):
    layout = PangoCairo.create_layout(ctx)
    fd = Pango.FontDescription()
    fd.set_family(family)
    if bold:
        fd.set_weight(Pango.Weight.BOLD)
    fd.set_absolute_size(max(1, int(size_px)) * Pango.SCALE)
    layout.set_font_description(fd)
    layout.set_text(text or "", -1)
    # Pango's own layout and shaping are used for both fit measurements and drawing.
    spacing = float(line_height)
    if spacing > 0:
        layout.set_spacing(int(round((spacing - 1.0) * max(1, int(size_px)) * Pango.SCALE)))
    if max_width is not None and max_width > 0:
        layout.set_width(int(max_width) * Pango.SCALE)
        layout.set_wrap(Pango.WrapMode.WORD_CHAR)
    if align == 'center':
        layout.set_alignment(Pango.Alignment.CENTER)
    elif align == 'right':
        layout.set_alignment(Pango.Alignment.RIGHT)
    return layout


def apply_letter_spacing(layout, spacing_px):
    """Apply uniform letter spacing without splitting grapheme clusters."""
    spacing = int(round(float(spacing_px) * Pango.SCALE))
    if not spacing:
        return
    attrs = Pango.AttrList()
    attr = Pango.attr_letter_spacing_new(spacing)
    attr.start_index = 0
    attr.end_index = 2**31 - 1
    attrs.insert(attr)
    layout.set_attributes(attrs)


def draw_layout(ctx, layout, x, y, rgba):
    ctx.save()
    ctx.set_source_rgba(*rgba)
    ctx.move_to(float(x), float(y))
    PangoCairo.show_layout(ctx, layout)
    ctx.restore()


def rounded_rect(ctx, x, y, w, h, radius):
    radius = min(float(radius), w / 2.0, h / 2.0)
    ctx.new_sub_path()
    ctx.arc(x + w - radius, y + radius, radius, -math.pi / 2, 0)
    ctx.arc(x + w - radius, y + h - radius, radius, 0, math.pi / 2)
    ctx.arc(x + radius, y + h - radius, radius, math.pi / 2, math.pi)
    ctx.arc(x + radius, y + radius, radius, math.pi, 3 * math.pi / 2)
    ctx.close_path()


def main():
    if len(sys.argv) < 3:
        print("Usage: render_text_element.py <input.json> <output.png>", file=sys.stderr)
        return 2

    in_path = sys.argv[1]
    out_path = sys.argv[2]

    try:
        with open(in_path, "r", encoding="utf-8") as f:
            data = json.load(f)
    except (json.JSONDecodeError, FileNotFoundError) as e:
        print("Error reading input JSON: %s" % e, file=sys.stderr)
        return 1

    text = str(data.get("text", ""))
    family = family_for(data.get("font"))

    # Load the plugin's bundled font directly so Bangla glyphs are available
    # even when the font family is not installed system-wide.
    font_file = data.get("font_file")
    if font_file:
        try:
            PangoCairo.font_map_get_default().add_font_file(str(font_file))
        except Exception:
            pass

    size = int(data.get("size", 42))
    max_width = int(data.get("max_width", 0))
    configured_width = bool(data.get("configured_width", max_width > 0))
    align = str(data.get("align", "left"))
    layout_width = max_width if configured_width else None
    layout_align = align if configured_width else None
    bold = bool(data.get("bold"))
    line_height = float(data.get("line_height", 1.3))
    px = int(data.get("padding_x", 0))
    py = int(data.get("padding_y", 0))
    radius = int(data.get("border_radius", 0))
    shadow_off = int(data.get("shadow_offset", 1))

    color = parse_color(data.get("color"), (1.0, 1.0, 1.0, 1.0))
    shadow = parse_color(data.get("shadow_color"), (0, 0, 0, 0.5))

    # Measure the wrapped layout first.
    probe = cairo.ImageSurface(cairo.FORMAT_ARGB32, 1, 1)
    pctx = cairo.Context(probe)
    max_lines = max(0, int(data.get("max_lines", 0)))
    max_height = max(0, int(data.get("max_height", 0)))
    letter_spacing = float(data.get("letter_spacing", 0))
    auto_scale = bool(data.get("auto_scale", False))
    min_size = max(1, int(data.get("min_font_size", size)))
    max_size = max(min_size, int(data.get("max_font_size", size)))
    if auto_scale:
        # Binary search for the largest font size satisfying Pango's shaped layout bounds.
        low, high, best = min_size, max_size, min_size
        while low <= high:
            candidate = (low + high) // 2
            probe_layout = make_layout(pctx, text, family, candidate, layout_width, bold, line_height, layout_align)
            apply_letter_spacing(probe_layout, letter_spacing)
            pw, ph = probe_layout.get_pixel_size()
            fits = (not max_lines or probe_layout.get_line_count() <= max_lines) and (not max_height or ph <= max_height)
            if fits:
                best, low = candidate, candidate + 1
            else:
                high = candidate - 1
        size = best

    layout = make_layout(pctx, text, family, size, layout_width, bold, line_height, layout_align)
    apply_letter_spacing(layout, letter_spacing)
    # If content cannot fit at the configured minimum, cap it instead of allowing overflow.
    effective_lines = max_lines
    if max_height:
        height_lines = max(1, int(max_height / max(1, size * max(0.8, line_height))))
        effective_lines = min(effective_lines, height_lines) if effective_lines else height_lines
    if effective_lines:
        layout.set_height(-effective_lines)
        layout.set_ellipsize(Pango.EllipsizeMode.END)
    tw, th = layout.get_pixel_size()

    box_w = max(1, (max_width if configured_width else tw) + 2 * px)
    box_h = max(1, th + 2 * py)

    surface = cairo.ImageSurface(cairo.FORMAT_ARGB32, box_w, box_h)
    ctx = cairo.Context(surface)

    # Transparent background.
    ctx.set_source_rgba(0, 0, 0, 0)
    ctx.paint()

    # Optional pill background behind the text.
    bg_value = data.get("background_color")
    if bg_value:
        bg = parse_color(bg_value, (0, 0, 0, 0))
        if bg[3] > 0:
            if radius > 0:
                rounded_rect(ctx, 0, 0, box_w, box_h, radius)
            else:
                ctx.rectangle(0, 0, box_w, box_h)
            ctx.set_source_rgba(*bg)
            ctx.fill()

    if shadow_off > 0:
        draw_layout(ctx, layout, px + shadow_off, py + shadow_off, shadow)
    draw_layout(ctx, layout, px, py, color)

    surface.write_to_png(out_path)
    print("CPIC_DIAGNOSTICS " + json.dumps({
        "font_family": family,
        "font_file": font_file or "",
        "engine": "PangoCairo/HarfBuzz",
        "requested_size": int(data.get("requested_size", data.get("size", 42))),
        "final_size": size,
        "line_count": int(layout.get_line_count()),
        "align": align,
        "text_width": int(tw),
        "text_height": int(th),
        "auto_scaled": bool(auto_scale and size != int(data.get("requested_size", data.get("size", 42)))) ,
        "fallback": False,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
