"""Non-destructive, font-sized dash leaders for the ends of PDF body rows."""
import math
import re

import fitz

import garis_akta as ga


DEFAULT_SETTINGS = {"enabled": False, "automatic": True, "right_margin_mm": 20.0, "fill_blank_lines": True}


def normalize_settings(value=None):
    value = value if isinstance(value, dict) else {}
    try:
        margin = float(value.get("right_margin_mm", 20.0))
        if not math.isfinite(margin):
            margin = 20.0
    except (ValueError, TypeError):
        margin = 20.0
    return {
        "enabled": value.get("enabled") is True,
        "automatic": value.get("automatic", True) is True,
        "right_margin_mm": max(5.0, min(80.0, margin)),
        "fill_blank_lines": value.get("fill_blank_lines", True) is True,
    }


def _span_is_visible(span, backgrounds):
    if span.get("alpha", 255) == 0:
        return False
    if span.get("color", 0) != 0xFFFFFF:
        return True
    # White letters on a colored card/image are visible. On bare white paper,
    # white Word placeholders must not move the end-of-line anchor.
    box = fitz.Rect(span["bbox"])
    return any((background & box).get_area() > 0 for background in backgrounds)


def body_rows(page):
    """Merge spans/blocks on one baseline and keep actual visible character ends.

    Grouping precedes filtering: a label and its value on the same baseline
    must never get a leader drawn through the value. Raw chars exclude the
    trailing whitespace that some Word PDF exporters include in span bboxes.
    """
    fragments = []
    backgrounds = [fitz.Rect(drawing["rect"]) for drawing in page.get_drawings()
                   if drawing.get("fill") is not None and drawing.get("fill_opacity", 1) > 0
                   and any(channel < .98 for channel in drawing["fill"])]
    backgrounds.extend(fitz.Rect(info["bbox"]) for info in page.get_image_info())
    for block in page.get_text("rawdict")["blocks"]:
        for line in block.get("lines", []):
            dx, dy = line.get("dir", (1, 0))
            if abs(dy) > 0.01 or dx < 0.99:
                continue
            chars = [(c, s) for s in line.get("spans", []) for c in s.get("chars", [])]
            ink = {id(span): _span_is_visible(span, backgrounds) for span in line.get("spans", [])}
            visible = [(c, s) for c, s in chars if c.get("c", "").strip() and ink[id(s)]]
            if not chars:
                continue
            last, span = max(visible or chars, key=lambda pair: pair[0]["bbox"][2])
            x0 = min(c["bbox"][0] for c, _ in (visible or chars))
            fragments.append({
                "text": "".join(c.get("c", "") for c, s in chars if ink[id(s)]).strip(),
                "x0": x0,
                "x1": last["bbox"][2] if visible else x0,
                "y0": min(c["bbox"][1] for c, _ in (visible or chars)),
                "y1": max(c["bbox"][3] for c, _ in (visible or chars)),
                "baseline": float(last["origin"][1]),
                "size": float(span["size"]),
                "blank": not visible,
                "hidden_text": not visible and any(c.get("c", "").strip() for c, _ in chars),
            })
    fragments.sort(key=lambda row: (row["baseline"], row["x0"]))
    rows = []
    for fragment in fragments:
        if rows and abs(fragment["baseline"] - rows[-1]["baseline"]) <= 1.2:
            row = rows[-1]
            row["parts"].append(fragment)
            row["x0"] = min(row["x0"], fragment["x0"])
            row["y0"] = min(row["y0"], fragment["y0"])
            row["y1"] = max(row["y1"], fragment["y1"])
            if fragment["x1"] > row["x1"]:
                row.update({k: fragment[k] for k in ("x1", "baseline", "size")})
        else:
            rows.append(dict(fragment, parts=[fragment]))

    width, height = page.cropbox.width, page.cropbox.height
    bottom = ga.body_bottom(page)
    signature = ga.find_signature_region(page)
    sizes = sorted(row["size"] for row in rows if not row["blank"] and row["y1"] <= bottom)
    body_size = sizes[len(sizes) // 2] if sizes else 12
    eligible = []
    for row in rows:
        parts = row.pop("parts")
        active = [p for p in parts if not p["blank"]] or parts
        last = max(active, key=lambda p: p["x1"])
        row.update({"x0": min(p["x0"] for p in active), "x1": last["x1"],
                    "y0": min(p["y0"] for p in active), "y1": max(p["y1"] for p in active),
                    "baseline": last["baseline"], "size": last["size"],
                    "blank": all(p["blank"] for p in active),
                    "hidden_text": any(p["hidden_text"] for p in active)})
        text = " ".join(p["text"] for p in sorted(active, key=lambda p: p["x0"]))
        row["text"] = text
        row["dash_only"] = bool(re.fullmatch(r"[-–—\s]+", text))
        if not any(c.isalnum() for c in text) and not row["dash_only"] and not row["blank"]:
            continue
        top = ga.TOP_IGNORE_FIRST_PAGE if page.number == 0 else ga.TOP_IGNORE
        if row["y0"] < top or row["y1"] > bottom:
            continue
        if signature and signature[0] <= (row["y0"] + row["y1"]) / 2 <= signature[1]:
            continue
        if ga.is_first_page_title_line(text, row["x0"], row["x1"], width, row["y0"], page.number):
            continue
        core = re.sub(r"[-–—_]+", " ", text).strip()
        section_label = bool(re.fullmatch(r"(?:pasal|bab)\s+[\divxlc]+[.:]?", core, re.I))
        centered = (abs((row["x0"] + row["x1"]) / 2 - width / 2) < 24
                    and row["x0"] > width * .16 and row["x1"] < width * .84)
        heading = section_label or ga.is_dash_banner_line(text) or ga.is_decorative_center_heading(
            text, row["x0"], row["x1"], width, row["y0"], page.number
        ) or (centered and len(core.split()) <= 12)
        row["center_heading"] = heading and not row["blank"] and bool(core)
        # A short centered uppercase row is a title, even halfway down a page.
        if core.isupper() and len(core.split()) <= 8 and centered:
            row["center_heading"] = True
        if row["center_heading"] and page.number == 0 and row["y0"] < 160:
            continue
        if not row["center_heading"] and core.isupper() and len(core.split()) <= 8 and row["size"] > body_size * 1.18:
            continue
        eligible.append(row)
    body = [row for row in eligible if not row["dash_only"] and not row["blank"] and not row["center_heading"]]
    first_body = min((row["baseline"] for row in body), default=math.inf)
    result = []
    for row in eligible:
        if row["blank"]:
            if row["baseline"] < first_body or not .85 * body_size <= row["size"] <= body_size * 1.2:
                continue
            signing_start = signature[0] - (0 if row["hidden_text"] else 2 * row["size"]) if signature else math.inf
            if row["baseline"] >= signing_start:
                continue
            if not any(abs(row["x0"] - anchor["x0"]) <= max(36, row["size"] * 3.5) for anchor in body):
                continue
        if row["dash_only"]:
            # Standalone strip rows in Word are real body rows. Require a
            # nearby paragraph in the same text column so title decorations,
            # isolated rules and blank signing guides stay outside the fill.
            if page.number == 0 and row["baseline"] < first_body:
                continue
            if not any(
                abs(row["baseline"] - anchor["baseline"]) <= max(36, row["size"] * 3.5)
                and abs(row["x0"] - anchor["x0"]) <= max(36, row["size"] * 3.5)
                for anchor in body
            ):
                continue
        result.append(row)
    return result


def _existing_horizontal_intervals(page):
    intervals = []
    for drawing in page.get_drawings():
        for item in drawing.get("items", []):
            if item[0] == "l":
                a, b = item[1:3]
                if abs(a.y - b.y) < 0.25:
                    intervals.append((min(a.x, b.x), max(a.x, b.x), a.y))
    return intervals


def _has_body_margin(rows, width):
    if not rows:
        return False
    span = max(row["x1"] for row in rows) - min(row["x0"] for row in rows)
    # Existing leaders are an explicit right-edge reference, including deeply
    # indented deed sections. Short prose alone cannot establish a text frame.
    explicit = any(re.search(r"[-–—_]{4,}\s*$", row["text"]) for row in rows)
    return span >= width * 0.65 or (explicit and span >= width * 0.45)


def build_document_leaders(doc, settings):
    """Return PDF-space line segments, leaving source text and PDF untouched.

    Auto margins use the longest body rows on this page; sparse continuation
    pages borrow a margin from pages of the same size, or fall back to 20 mm.
    Existing text dashes count as text; existing vector leaders are subtracted
    so reopening and processing an exported PDF does not double the strokes.
    """
    settings = normalize_settings(settings)
    if not settings["enabled"]:
        return {}
    pages = [body_rows(page) for page in doc]
    references = [[row for row in rows if not row["dash_only"] and not row["blank"] and not row["center_heading"]] for rows in pages]
    candidates = {}
    for page, rows in zip(doc, references):
        width = page.cropbox.width
        if rows:
            right = max(row["x1"] for row in rows)
            if _has_body_margin(rows, width):
                candidates.setdefault(round(width), []).append(width - right)
    leaders = {}
    for index, rows in enumerate(pages):
        page = doc[index]
        width = page.cropbox.width
        if settings["automatic"]:
            margins = sorted(candidates.get(round(width), []))
            fallback = margins[len(margins) // 2] if margins else 20 * 72 / 25.4
            reference = references[index]
            right = max((row["x1"] for row in reference), default=0)
            edge = right if _has_body_margin(reference, width) else width - fallback
        else:
            edge = width - settings["right_margin_mm"] * 72 / 25.4
        edge = min(width - 5, edge)
        left = min((row["x0"] for row in references[index]), default=width - edge)
        left = max(5, left)
        occupied = _existing_horizontal_intervals(page) if rows else []
        images = [fitz.Rect(info["bbox"]) for info in page.get_image_info()]
        result = []
        for row in rows:
            if row["blank"] and not settings["fill_blank_lines"]:
                continue
            size = row["size"]
            start = row["x0"] if row["blank"] else row["x1"] + max(0.8, size * 0.08)
            y = row["baseline"] - size * 0.26
            if row["blank"] and any(image.y0 <= y <= image.y1 and image.x0 < edge and image.x1 > start for image in images):
                continue
            dash, gap = size * 0.27, max(0.5, size * 0.07)
            if edge - start < dash and not row["center_heading"]:
                continue
            ranges = [(start, edge)]
            if row["center_heading"]:
                ranges.insert(0, (left, row["x0"] - max(2, size * 0.2)))
                ranges[1] = (row["x1"] + max(2, size * 0.2), edge)
            for a, b, line_y in occupied:
                if abs(line_y - y) > max(1, size * 0.12):
                    continue
                remaining = []
                for lo, hi in ranges:
                    if b < lo or a > hi:
                        remaining.append((lo, hi))
                    else:
                        if a > lo:
                            remaining.append((lo, a - 0.5))
                        if b < hi:
                            remaining.append((b + 0.5, hi))
                ranges = remaining
            for lo, hi in ranges:
                if hi - lo >= dash:
                    result.append({"x0": lo, "x1": hi, "y": y, "dash": dash, "blank": row["blank"],
                                   "gap": gap, "width": max(0.45, min(0.85, size * 0.05))})
        if result:
            leaders[index] = result
    return leaders


def dash_strokes(leader):
    """The same explicit strokes drive preview, thumbnail, export and print."""
    x = leader["x0"]
    matrix = fitz.Matrix(leader.get("matrix", (1, 0, 0, 1, 0, 0)))
    while x < leader["x1"] - 0.05:
        a = fitz.Point(x, leader["y"]) * matrix
        b = fitz.Point(min(x + leader["dash"], leader["x1"]), leader["y"]) * matrix
        yield a.x, a.y, b.x, b.y
        x += leader["dash"] + leader["gap"]


def draw_page_leaders(page, leaders, color=(0, 0, 0)):
    if not leaders:
        return
    shape = page.new_shape()
    for leader in leaders:
        for x0, y0, x1, y1 in dash_strokes(leader):
            shape.draw_line((x0, y0), (x1, y1))
        # Dash leaders are always black, independently of the rail color.
        shape.finish(color=(0, 0, 0), width=leader["width"], closePath=False)
    shape.commit()
