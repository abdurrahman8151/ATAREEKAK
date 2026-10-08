#!/usr/bin/env python3
"""Render docs/audit/backlog-status.png from the live table in docs/audit/BACKLOG.md.

The numbers are PARSED, never hardcoded, so the picture cannot drift from the source of truth.
Run:  python docs/audit/render-backlog-status.py
"""
import re
import sys
from collections import Counter
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[2]
BACKLOG = ROOT / "docs" / "audit" / "BACKLOG.md"
OUT = ROOT / "docs" / "audit" / "backlog-status.png"

W, H = 1500, 1010  # the original canvas, so this drops in without rescaling anything
BG = (250, 250, 252)
INK = (28, 30, 36)
MUTED = (110, 116, 128)
RULE = (222, 224, 230)
CARD = (255, 255, 255)

# status -> fill. VERIFIED FIX leads because it dominates and is the number that matters.
COLORS = {
    "VERIFIED FIX": (34, 139, 94),
    "RECORDED":     (108, 117, 125),
    "PARTIAL":      (214, 158, 46),
    "OPEN":         (199, 62, 62),
    "BLOCKED":      (150, 96, 176),
    "DEFERRED":     (140, 148, 160),
    "SUPERSEDED":   (176, 182, 192),
    "GATED":        (52, 120, 176),
}
ORDER = ["VERIFIED FIX", "RECORDED", "PARTIAL", "OPEN", "BLOCKED", "DEFERRED", "SUPERSEDED", "GATED"]


def parse_backlog(text: str):
    """Return (Counter of normalised statuses, list of (order, id, raw_status))."""
    rows = []
    for line in text.splitlines():
        if not line.lstrip().startswith("|"):
            continue
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if len(cells) < 8 or not cells[0].isdigit():
            continue
        raw = cells[4]
        # Strip markdown emphasis, then any trailing parenthetical qualifier, so that
        # "**VERIFIED FIX** (both halves)" counts as VERIFIED FIX and not as its own status.
        norm = re.sub(r"\s*\(.*?\)\s*$", "", raw.replace("*", "")).strip()
        rows.append((int(cells[0]), cells[1], raw, norm))
    if not rows:
        sys.exit("no backlog rows parsed - refusing to render an empty chart")
    return Counter(r[3] for r in rows), rows


def load_font(size, bold=False):
    """Prefer a real UI font; fall back to Pillow's default if none is installed."""
    names = ["segoeuib.ttf", "arialbd.ttf"] if bold else ["segoeui.ttf", "arial.ttf"]
    for name in names:
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def main():
    census, rows = parse_backlog(BACKLOG.read_text(encoding="utf-8"))
    total = len(rows)
    unknown = [s for s in census if s not in COLORS]
    if unknown:
        sys.exit(f"unmapped status(es), refusing to guess a colour: {unknown}")

    img = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(img)

    f_title = load_font(40, bold=True)
    f_sub = load_font(20)
    f_num = load_font(30, bold=True)
    f_lbl = load_font(21, bold=True)
    f_note = load_font(17)
    f_small = load_font(16)

    d.text((64, 52), "Audit backlog status", font=f_title, fill=INK)
    d.text((64, 104), f"{total} tracked items  -  docs/audit/BACKLOG.md", font=f_sub, fill=MUTED)
    d.line([(64, 148), (W - 64, 148)], fill=RULE, width=2)

    done = census.get("VERIFIED FIX", 0)
    pct = done / total * 100
    d.text((64, 182), f"{done} of {total} verified fix", font=f_lbl, fill=INK)
    d.text((64, 214), f"{pct:.1f}%", font=f_num, fill=COLORS["VERIFIED FIX"])

    # Stacked proportion bar
    bx, by, bw, bh = 64, 268, W - 128, 34
    x = float(bx)
    for status in ORDER:
        n = census.get(status, 0)
        if not n:
            continue
        seg = bw * n / total
        d.rectangle([x, by, x + seg, by + bh], fill=COLORS[status])
        x += seg
    d.rectangle([bx, by, bx + bw, by + bh], outline=RULE, width=1)

    # Legend / count cards, four per row
    cx, cy, cw, ch, gap = 64, 336, 330, 84, 22
    for i, status in enumerate(ORDER):
        n = census.get(status, 0)
        col, row = i % 4, i // 4
        x0 = cx + col * (cw + gap)
        y0 = cy + row * (ch + gap)
        d.rounded_rectangle([x0, y0, x0 + cw, y0 + ch], radius=10, fill=CARD, outline=RULE, width=1)
        d.rounded_rectangle([x0 + 18, y0 + 26, x0 + 30, y0 + 58], radius=4, fill=COLORS[status])
        d.text((x0 + 46, y0 + 16), str(n), font=f_num, fill=INK)
        d.text((x0 + 46, y0 + 52), status, font=f_note, fill=MUTED)

    # Outstanding work, so the chart is not just a victory lap
    oy = 560
    d.line([(64, oy - 22), (W - 64, oy - 22)], fill=RULE, width=2)
    d.text((64, oy), "Not yet closed", font=f_lbl, fill=INK)

    outstanding = sorted(
        [(o, i, s) for o, i, raw, s in rows if s != "VERIFIED FIX"],
        key=lambda t: t[0],
    )
    for i, (order, rid, status) in enumerate(outstanding):
        col, row = i % 2, i // 2
        x0 = 64 + col * ((W - 128) // 2)
        y0 = oy + 46 + row * 30
        d.text((x0, y0), f"{order:>3}", font=f_small, fill=MUTED)
        d.text((x0 + 42, y0), rid, font=f_small, fill=INK)
        d.text((x0 + 130, y0), status, font=f_small, fill=COLORS.get(status, MUTED))

    d.text(
        (64, H - 46),
        "RV-04b is IN PROGRESS (identity floor green; bisect + needle outstanding) - "
        "not yet at a terminal state, so it still counts as OPEN.",
        font=f_small,
        fill=MUTED,
    )
    d.text(
        (64, H - 22),
        "Generated by docs/audit/render-backlog-status.py - counts are parsed from BACKLOG.md, not typed in.",
        font=f_small,
        fill=MUTED,
    )

    img.save(OUT, "PNG", optimize=True)
    print(f"wrote {OUT.relative_to(ROOT)}  ({total} rows)")
    for s in ORDER:
        if census.get(s):
            print(f"  {s:<14} {census[s]:>3}")


if __name__ == "__main__":
    main()