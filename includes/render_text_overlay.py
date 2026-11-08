#!/usr/bin/env python3
"""Render transparent title/date overlay using PangoCairo.

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
    if not value:
        return default
    s = str(value).strip()

    if s.startswith("#") and len(s) in (4, 7):
        if len(s) == 4:
            r = int(s[1] * 2, 16)
            g = int(s[2] * 2, 16)
            b = int(s[3] * 2, 16)
        else:
            r = int(s[1:3], 16)
            g = int(s[3:5], 16)
            b = int(s[5:7], 16)
        return (r / 255.0, g / 255.0, b / 255.0, 1.0)

    if s.startswith("rgba(") and s.endswith(")"):
        parts = [p.strip() for p in s[5:-1].split(",")]
        if len(parts) == 4:
            r = float(parts[0])
            g = float(parts[1])
            b = float(parts[2])
            a = float(parts[3])
            return (max(0.0, min(r / 255.0, 1.0)), max(0.0, min(g / 255.0, 1.0)), max(0.0, min(b / 255.0, 1.0)), max(0.0, min(a, 1.0)))

    return default


def family_for(name):
    mapping = {
        "Noto Serif Bengali": "Noto Serif Bengali",
        "Hind Siliguri": "Hind Siliguri",
        "SolaimanLipi": "SolaimanLipi",
        "Siyam Rupali": "Siyam Rupali",
    }
    if name in mapping:
        return mapping[name]
    return name or "Noto Serif Bengali"


def make_layout(ctx, text, family, size_px, width_px=None, weight=Pango.Weight.NORMAL):
    layout = PangoCairo.create_layout(ctx)
    fd = Pango.FontDescription()
    fd.set_family(family)
    fd.set_weight(weight)
    fd.set_absolute_size(max(1, int(size_px)) * Pango.SCALE)
    layout.set_font_description(fd)
    layout.set_text(text or "", -1)
    if width_px is not None and width_px > 0:
        layout.set_width(int(width_px) * Pango.SCALE)
        layout.set_wrap(Pango.WrapMode.WORD_CHAR)
    return layout


def draw_layout(ctx, layout, x, y, rgba):
    ctx.save()
    ctx.set_source_rgba(*rgba)
    ctx.move_to(float(x), float(y))
    PangoCairo.show_layout(ctx, layout)
    ctx.restore()


def main():
    if len(sys.argv) < 3:
        print("Usage: render_text_overlay.py <input.json> <output.png>", file=sys.stderr)
        return 2

    in_path = sys.argv[1]
    out_path = sys.argv[2]

    try:
        with open(in_path, "r", encoding="utf-8") as f:
            data = json.load(f)
    except (json.JSONDecodeError, FileNotFoundError) as e:
        print(f"Error reading input JSON: {e}", file=sys.stderr)
        return 1

    w = int(data.get("canvas_width", 1080))
    h = int(data.get("canvas_height", 1080))

    title = str(data.get("title", ""))
    date_text = str(data.get("bangla_date", ""))

    title_font = family_for(data.get("title_font", "Noto Serif Bengali"))
    date_font = family_for(data.get("date_font", "Hind Siliguri"))

    # Load the plugin's bundled fonts directly so Bangla glyphs are available
    # even when the font families are not installed system-wide.
    for key in ("title_font_file", "date_font_file"):
        ff = data.get(key)
        if ff:
            try:
                PangoCairo.font_map_get_default().add_font_file(str(ff))
            except Exception:
                pass

    title_size = int(data.get("title_size", 42))
    date_size = int(data.get("date_size", 28))

    title_x = int(data.get("title_offset_x", 40))
    title_y = int(data.get("title_offset_y", 750))
    date_x = int(data.get("date_offset_x", 40))
    date_y = int(data.get("date_offset_y", 950))

    title_color = parse_color(data.get("title_color", "#FFFFFF"), (1, 1, 1, 1))
    date_color = parse_color(data.get("date_color", "#FFFFFF"), (1, 1, 1, 1))
    date_bg = parse_color(data.get("date_bg_color", "#E53935"), (0.898, 0.224, 0.208, 1.0))
    title_shadow = parse_color(data.get("title_shadow_color", "rgba(0,0,0,0.65)"), (0, 0, 0, 0.65))
    date_shadow = parse_color(data.get("date_shadow_color", "rgba(0,0,0,0.5)"), (0, 0, 0, 0.5))

    surface = cairo.ImageSurface(cairo.FORMAT_ARGB32, w, h)
    ctx = cairo.Context(surface)

    # Transparent background.
    ctx.set_source_rgba(0, 0, 0, 0)
    ctx.paint()

    if title:
        title_layout = make_layout(
            ctx,
            title,
            title_font,
            title_size,
            width_px=max(100, w - title_x - 40),
            weight=Pango.Weight.BOLD,
        )
        draw_layout(ctx, title_layout, title_x + 2, title_y + 2, title_shadow)
        draw_layout(ctx, title_layout, title_x, title_y, title_color)

    if date_text:
        date_layout = make_layout(
            ctx,
            date_text,
            date_font,
            date_size,
            width_px=None,
            weight=Pango.Weight.SEMIBOLD,
        )
        text_w, text_h = date_layout.get_pixel_size()
        text_w = max(text_w, 120)
        text_h = max(text_h, int(math.ceil(date_size * 1.3)))

        pad = 16
        rx = float(date_x - pad)
        ry = float((date_y - 4) - pad)
        rw = float(text_w + pad * 2)
        rh = float(text_h + pad * 2)
        radius = 6.0

        # Rounded rectangle background for date label.
        ctx.save()
        ctx.new_sub_path()
        ctx.arc(rx + rw - radius, ry + radius, radius, -math.pi / 2, 0)
        ctx.arc(rx + rw - radius, ry + rh - radius, radius, 0, math.pi / 2)
        ctx.arc(rx + radius, ry + rh - radius, radius, math.pi / 2, math.pi)
        ctx.arc(rx + radius, ry + radius, radius, math.pi, 3 * math.pi / 2)
        ctx.close_path()
        ctx.set_source_rgba(*date_bg)
        ctx.fill()
        ctx.restore()

        draw_layout(ctx, date_layout, date_x + 1, date_y + 1, date_shadow)
        draw_layout(ctx, date_layout, date_x, date_y, date_color)

    surface.write_to_png(out_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
