import argparse
import json
import os
import re
import unicodedata
from pathlib import Path

import cv2
import fitz
import numpy as np
import garis_akta as ga


LINE_COLOR = (17 / 255, 24 / 255, 39 / 255)
LINE_WIDTH = 1.0
ENABLE_CAPS = True
CAP_DX = ga.CAP_TOP_DX
CAP_DY = ga.CAP_TOP_DY


def median(values):
    vals = sorted(float(v) for v in values)
    if not vals:
        return 0.0
    n = len(vals)
    m = n // 2
    if n % 2 == 1:
        return vals[m]
    return (vals[m - 1] + vals[m]) / 2.0


def load_scan_model(path):
    p = Path(path)
    if not p.exists():
        raise RuntimeError(f"Model scan tidak ditemukan: {path}")
    data = json.loads(p.read_text(encoding="utf-8"))
    if data.get("type") != "scan_line_position_model":
        raise RuntimeError("File model bukan scan_line_position_model.")
    return data


def page_to_bgr(page, zoom):
    pix = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), alpha=False)
    arr = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, 3)
    return cv2.cvtColor(arr, cv2.COLOR_RGB2BGR)


def detect_text_boxes(bgr):
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
    bw = cv2.adaptiveThreshold(
        gray,
        255,
        cv2.ADAPTIVE_THRESH_MEAN_C,
        cv2.THRESH_BINARY_INV,
        35,
        15,
    )
    bw = cv2.medianBlur(bw, 3)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (9, 3))
    joined = cv2.morphologyEx(bw, cv2.MORPH_CLOSE, kernel, iterations=1)

    num, _, stats, _ = cv2.connectedComponentsWithStats(joined, connectivity=8)
    boxes = []
    for i in range(1, num):
        x, y, w, h, area = stats[i]
        if area < 120:
            continue
        if h < 12 or h > 120:
            continue
        if w < 28:
            continue
        boxes.append((int(x), int(y), int(w), int(h)))
    return boxes


def cluster_by_x(boxes, x_tol):
    cols = []
    for b in sorted(boxes, key=lambda z: (z[0], z[1])):
        x = float(b[0])
        best_idx = None
        best_dx = None
        for i, col in enumerate(cols):
            dx = abs(x - col["x_ref"])
            if dx <= x_tol and (best_dx is None or dx < best_dx):
                best_idx = i
                best_dx = dx

        if best_idx is None:
            cols.append({"x_ref": x, "boxes": [b]})
        else:
            col = cols[best_idx]
            col["boxes"].append(b)
            count = float(len(col["boxes"]))
            col["x_ref"] = ((col["x_ref"] * (count - 1.0)) + x) / count

    for col in cols:
        col["boxes"].sort(key=lambda z: (z[1], z[0]))
        col["x_ref"] = median([x for x, _, _, _ in col["boxes"]])
    cols.sort(key=lambda c: c["x_ref"])
    return cols


def split_runs(boxes, join_gap):
    if not boxes:
        return []
    runs = []
    run = [boxes[0]]
    for b in boxes[1:]:
        px, py, pw, ph = run[-1]
        x, y, w, h = b
        gap = y - (py + ph)
        if gap > join_gap:
            runs.append(run)
            run = [b]
        else:
            run.append(b)
    runs.append(run)
    return runs


def page_text_word_count(page):
    words = page.get_text("words")
    return len(words) if words else 0


def has_long_dash_run(text):
    return bool(re.search(r"[-\u2013\u2014_]{5,}", text or ""))


def collect_dash_rows_from_words(page):
    words = page.get_text("words") or []
    rows = {}
    for item in words:
        if len(item) < 8:
            continue
        x0, y0, x1, y1, txt, block_no, line_no, word_no = item[:8]
        key = (int(block_no), int(line_no))
        row = rows.get(key)
        token = str(txt)
        if row is None:
            rows[key] = {
                "x0": float(x0),
                "y0": float(y0),
                "x1": float(x1),
                "y1": float(y1),
                "words": [(int(word_no), float(x0), token)],
            }
        else:
            row["x0"] = min(float(row["x0"]), float(x0))
            row["y0"] = min(float(row["y0"]), float(y0))
            row["x1"] = max(float(row["x1"]), float(x1))
            row["y1"] = max(float(row["y1"]), float(y1))
            row["words"].append((int(word_no), float(x0), token))

    out = []
    for row in rows.values():
        toks = sorted(row["words"], key=lambda z: (z[0], z[1]))
        text = " ".join(tok for _, _, tok in toks).strip()
        if not text:
            continue
        if ga.RE_PAGE_NUMBER_ONLY.match(text):
            continue
        if not has_long_dash_run(text):
            continue
        out.append(
            {
                "text": text,
                "x0": float(row["x0"]),
                "x0_raw": float(row["x0"]),
                "y0": float(row["y0"]),
                "y1": float(row["y1"]),
            }
        )

    out.sort(key=lambda z: (float(z["y0"]), float(z["x0"])))
    return out


def collect_left_marker_tokens(page):
    words = page.get_text("words") or []
    out = []
    for item in words:
        if len(item) < 8:
            continue
        x0, y0, x1, y1, txt = item[:5]
        token = str(txt).strip()
        if not token:
            continue
        if not (ga.is_enum_token(token) or is_dash_like_token(token)):
            continue
        out.append(
            {
                "x0": float(x0),
                "x1": float(x1),
                "y0": float(y0),
                "y1": float(y1),
                "text": token,
            }
        )
    out.sort(key=lambda z: (float(z["y0"]), float(z["x0"])))
    return out


def is_dash_like_token(token):
    t = str(token or "").strip()
    if not t or len(t) > 4:
        return False

    dash_chars = set("-–—−‒‐‑﹘﹣－˗_")
    if all(ch in dash_chars for ch in t):
        return True

    # Fallback: karakter punctuation/symbol pendek yang sering jadi marker
    # akibat OCR/font mapping PDF.
    cats = [unicodedata.category(ch) for ch in t]
    if all(c.startswith("P") or c.startswith("S") for c in cats):
        return True
    return False


def refine_left_anchor_with_markers(x_anchor, y0, y1, markers):
    if not markers:
        return float(x_anchor)

    line_h = max(1.0, float(y1) - float(y0))
    y_mid = (float(y0) + float(y1)) / 2.0
    tol_y = max(2.2, 0.35 * line_h + 0.8)
    best = None
    best_key = None
    for mk in markers:
        mk_mid = (float(mk["y0"]) + float(mk["y1"])) / 2.0
        if abs(mk_mid - y_mid) > tol_y:
            continue
        if float(mk["x0"]) >= float(x_anchor):
            continue
        gap = float(x_anchor) - float(mk["x1"])
        if gap < -0.5 or gap > 80.0:
            continue
        key = (gap, -float(mk["x0"]))
        if best_key is None or key < best_key:
            best_key = key
            best = mk

    if best is None:
        return float(x_anchor)
    return min(float(x_anchor), float(best["x0"]))


def merge_text_no_merge_segments(page_segments):
    if not page_segments:
        return []

    heights = [max(1.0, float(s["y1"]) - float(s["y0"])) for s in page_segments]
    h_med = median(heights)
    x_tol = max(4.0, min(8.0, 0.45 * h_med))
    gap_tol = max(16.0, min(30.0, 1.9 * h_med))

    cols = []
    for seg in sorted(page_segments, key=lambda s: (float(s["x"]), float(s["y0"]))):
        x = float(seg["x"])
        chosen = None
        best_dx = None
        for i, col in enumerate(cols):
            dx = abs(x - float(col["x_ref"]))
            if dx <= x_tol and (best_dx is None or dx < best_dx):
                chosen = i
                best_dx = dx
        if chosen is None:
            cols.append({"x_ref": x, "items": [dict(seg)]})
        else:
            col = cols[chosen]
            col["items"].append(dict(seg))
            n = float(len(col["items"]))
            col["x_ref"] = ((float(col["x_ref"]) * (n - 1.0)) + x) / n

    out = []
    for col in cols:
        items = sorted(col["items"], key=lambda s: (float(s["y0"]), float(s["y1"])))
        cur = dict(items[0])
        cur["x"] = float(cur["x"])
        for seg in items[1:]:
            gap = max(0.0, float(seg["y0"]) - float(cur["y1"]))
            if gap <= gap_tol:
                cur["y0"] = min(float(cur["y0"]), float(seg["y0"]))
                cur["y1"] = max(float(cur["y1"]), float(seg["y1"]))
                # Pertahankan sisi kiri agar tidak meleset ke kanan.
                cur["x"] = min(float(cur["x"]), float(seg["x"]))
            else:
                out.append(cur)
                cur = dict(seg)
                cur["x"] = float(cur["x"])
        out.append(cur)

    out.sort(key=lambda s: (int(s["page"]), float(s["y0"]), float(s["x"])))
    return out


def build_segments_from_text_page(page_no, page, model=None, no_merge=False, outside_shift=0.0):
    cfg = ga.LayoutConfig(
        max_indent_from_base=ga.MAX_INDENT_FROM_BASE,
        indent_tol=ga.INDENT_TOL,
        line_gap_break=ga.LINE_GAP_BREAK,
    )
    lines = ga.extract_lines(page, cfg, extra_indent=ga.VALIDATION_EXTRA_INDENT)
    if no_merge:
        filtered = []
        for ln in lines:
            txt = str(ln.get("text", "")).strip()
            if not txt:
                continue
            if ga.RE_PAGE_NUMBER_ONLY.match(txt):
                continue
            filtered.append(ln)

        extra_dash_rows = collect_dash_rows_from_words(page)
        for cand in extra_dash_rows:
            y_mid = (float(cand["y0"]) + float(cand["y1"])) / 2.0
            exists = False
            for ln in filtered:
                y_ln = (float(ln["y0"]) + float(ln["y1"])) / 2.0
                if abs(y_ln - y_mid) <= 1.8:
                    exists = True
                    break
            if not exists:
                filtered.append(cand)

        filtered.sort(key=lambda ln: (float(ln["y0"]), float(ln["x0"])))
        markers = collect_left_marker_tokens(page)

        if not filtered:
            return []

        line_heights = [max(1.0, float(ln["y1"] - ln["y0"])) for ln in filtered]
        line_h_med = median(line_heights)

        # Untuk PDF teks, offset scan-model terlalu besar; pakai offset kecil agar
        # garis tetap dekat sisi kiri teks namun tetap di luar.
        base_offset = -max(1.5, min(6.0, float(line_h_med) * 0.22))
        offset_pt = float(base_offset) - float(outside_shift)
        out = []
        for ln in filtered:
            x_body = float(ln["x0"])
            x_raw = float(ln.get("x0_raw", x_body))
            x_anchor = min(x_body, x_raw)
            x_anchor = refine_left_anchor_with_markers(x_anchor, float(ln["y0"]), float(ln["y1"]), markers)
            x = x_anchor + offset_pt
            # Jamin garis berada di kiri anchor teks (termasuk marker enum/dash).
            x = min(x, x_anchor - 0.8)
            y0 = float(ln["y0"]) + 1.0
            y1 = float(ln["y1"]) - 1.0
            if y1 <= y0 + 2.0:
                continue
            if x < 5.0:
                x = 5.0

            out.append(
                {
                    "page": int(page_no),
                    "x": x,
                    "y0": y0,
                    "y1": y1,
                    "is_stub": False,
                    "draw_start_cap": False,
                    "draw_end_cap": False,
                }
            )
        out.sort(key=lambda s: (s["y0"], s["x"]))
        return out

    sections = ga.filter_sections(ga.split_runs(lines, cfg))
    segs = ga.plan_segments(sections, cfg)
    out = []
    for s in segs:
        out.append(
            {
                "page": int(s["page"]),
                "x": float(s["x"]),
                "y0": float(s["y0"]),
                "y1": float(s["y1"]),
                "is_stub": bool(s.get("is_stub", False)),
                "draw_start_cap": bool(s.get("draw_start_cap", False)),
                "draw_end_cap": bool(s.get("draw_end_cap", False)),
            }
        )

    if outside_shift:
        for seg in out:
            seg["x"] = max(5.0, float(seg["x"]) - float(outside_shift))
    return out


def build_segments_from_scan_page(page_no, bgr, model, zoom, no_merge=False, outside_shift=0.0):
    boxes = detect_text_boxes(bgr)
    if not boxes:
        return []

    h_img, w_img = bgr.shape[:2]
    left_boxes = [b for b in boxes if b[0] < int(0.62 * w_img)]
    if not left_boxes:
        return []

    text_h = float(model.get("text_h_median", 20.0))
    offset_base = float(model.get("offset_median_calibrated", model.get("offset_median", -35.0)))
    # outside_shift > 0 menggeser garis lebih ke kiri (lebih luar dari teks).
    offset_px = offset_base - float(outside_shift)
    x_tol = max(10.0, text_h * 0.9)
    join_gap = max(14.0, text_h * 1.6)

    cols = cluster_by_x(left_boxes, x_tol=x_tol)
    segments = []

    for col in cols:
        col_boxes = col["boxes"]
        if len(col_boxes) < 3:
            continue

        runs = split_runs(col_boxes, join_gap=join_gap)
        for run in runs:
            if len(run) < 3:
                continue

            y0 = min(y for _, y, _, _ in run) + 2.0
            y1 = max(y + h for _, y, _, h in run) - 2.0
            if y1 <= y0 + 3:
                continue

            widths = [w for _, _, w, _ in run]
            w_med = median(widths)
            main_boxes = [b for b in run if b[2] >= (0.65 * w_med)]
            if not main_boxes:
                main_boxes = run

            to_pdf = 1.0 / float(zoom)
            if no_merge:
                for bx, by, bw, bh in main_boxes:
                    x_line = bx + offset_px
                    # Jamin garis tetap di kiri box teks scan.
                    x_line = min(x_line, float(bx) - 1.0)
                    if x_line < 5 or x_line > (0.9 * w_img):
                        continue
                    by0 = by + 2.0
                    by1 = by + bh - 2.0
                    if by1 <= by0 + 3:
                        continue
                    segments.append(
                        {
                            "page": page_no,
                            "x": x_line * to_pdf,
                            "y0": by0 * to_pdf,
                            "y1": by1 * to_pdf,
                            "is_stub": False,
                            "draw_start_cap": False,
                            "draw_end_cap": False,
                        }
                    )
                continue

            x_main = median([x for x, _, _, _ in main_boxes]) + offset_px
            if x_main < 5 or x_main > (0.9 * w_img):
                continue

            stub = None
            if len(run) >= 2:
                fx, fy, fw, fh = run[0]
                sx, sy, sw, sh = run[1]
                marker_like = (
                    fw <= (0.45 * w_med)
                    and (sx - (fx + fw)) >= 4
                    and abs(fy - sy) <= max(10, int(0.6 * max(fh, sh)))
                )
                if marker_like:
                    stub_x = fx + offset_px
                    stub_y0 = fy + 2.0
                    stub_y1 = min(fy + fh - 2.0, sy - 1.0)
                    if stub_y1 > stub_y0 + 8:
                        stub = (stub_x, stub_y0, stub_y1)
                        y0 = max(y0, sy + 1.0)

            if stub is not None:
                sx, sy0, sy1 = stub
                segments.append(
                    {
                        "page": page_no,
                        "x": sx * to_pdf,
                        "y0": sy0 * to_pdf,
                        "y1": sy1 * to_pdf,
                        "is_stub": True,
                        "draw_start_cap": False,
                        "draw_end_cap": False,
                    }
                )

            segments.append(
                {
                    "page": page_no,
                    "x": x_main * to_pdf,
                    "y0": y0 * to_pdf,
                    "y1": y1 * to_pdf,
                    "is_stub": False,
                    "draw_start_cap": False,
                    "draw_end_cap": False,
                }
            )

    segments.sort(key=lambda s: (s["y0"], s["x"]))
    return segments


def draw_segments(doc, segments, auto_page_caps=True, line_color=LINE_COLOR):
    by_page = {}
    for seg in segments:
        by_page.setdefault(int(seg["page"]), []).append(seg)

    for page_no, segs in by_page.items():
        page = doc[page_no]
        segs_sorted = sorted(segs, key=lambda s: (s["y0"], s["x"]))
        non_stub_idx = [i for i, s in enumerate(segs_sorted) if not bool(s.get("is_stub", False))]
        first_main = non_stub_idx[0] if non_stub_idx else None
        last_main = non_stub_idx[-1] if non_stub_idx else None

        for i, seg in enumerate(segs_sorted):
            x = float(seg["x"])
            y0 = float(seg["y0"])
            y1 = float(seg["y1"])
            page.draw_line((x, y0), (x, y1), color=line_color, width=LINE_WIDTH)

            has_start_cap = bool(seg.get("draw_start_cap", False))
            has_end_cap = bool(seg.get("draw_end_cap", False))
            auto_start = bool(auto_page_caps and first_main is not None and i == first_main)
            auto_end = bool(auto_page_caps and last_main is not None and i == last_main)

            if ENABLE_CAPS and (has_start_cap or auto_start):
                page.draw_line((x, y0), (x + CAP_DX, y0 + CAP_DY), color=line_color, width=LINE_WIDTH)
            if ENABLE_CAPS and (has_end_cap or auto_end):
                page.draw_line((x, y1), (x + CAP_DX, y1 - CAP_DY), color=line_color, width=LINE_WIDTH)


def apply_scan_model(input_pdf, output_pdf, model_path, zoom):
    return apply_scan_model_with_options(
        input_pdf=input_pdf,
        output_pdf=output_pdf,
        model_path=model_path,
        zoom=zoom,
        force_scan=False,
        no_merge=False,
        outside_shift=0.0,
    )


def apply_scan_model_with_options(input_pdf, output_pdf, model_path, zoom, force_scan=False, no_merge=False, outside_shift=0.0, line_color=LINE_COLOR):
    model = load_scan_model(model_path)
    doc = fitz.open(input_pdf)
    try:
        all_segments = []
        for page in doc:
            wc = page_text_word_count(page)
            prefer_text = (wc > 0)

            segs = []
            if (not force_scan) and prefer_text:
                if no_merge:
                    segs = build_segments_from_text_page(
                        page.number,
                        page,
                        model=model,
                        no_merge=True,
                        outside_shift=outside_shift,
                    )
                else:
                    base_segs = build_segments_from_text_page(
                        page.number,
                        page,
                        model=model,
                        no_merge=True,
                        outside_shift=outside_shift,
                    )
                    segs = merge_text_no_merge_segments(base_segs)

            if force_scan or not segs:
                bgr = page_to_bgr(page, zoom=zoom)
                segs_scan = build_segments_from_scan_page(
                    page.number,
                    bgr,
                    model,
                    zoom,
                    no_merge=no_merge,
                    outside_shift=outside_shift,
                )
                if force_scan or not segs:
                    segs = segs_scan
            all_segments.extend(segs)

        if not all_segments:
            raise RuntimeError("Tidak menemukan segmen garis dari scan pada dokumen ini.")

        draw_segments(doc, all_segments, auto_page_caps=(not no_merge), line_color=line_color)

        final_output = output_pdf
        try:
            if os.path.exists(output_pdf):
                os.remove(output_pdf)
            doc.save(output_pdf)
        except PermissionError:
            base, ext = os.path.splitext(output_pdf)
            final_output = f"{base}_v2{ext}"
            if os.path.exists(final_output):
                os.remove(final_output)
            doc.save(final_output)

        return final_output, len(all_segments)
    finally:
        doc.close()


def main():
    parser = argparse.ArgumentParser(description="Aplikasikan model posisi garis scan ke PDF akta.")
    parser.add_argument("-i", "--input", required=True, help="PDF input")
    parser.add_argument("-o", "--output", required=True, help="PDF output")
    parser.add_argument("--scan-model-file", default="scan_line_model_datatrain_v2.json", help="Model scan JSON")
    parser.add_argument("--zoom", type=float, default=2.0, help="Zoom render PDF saat deteksi")
    parser.add_argument(
        "--force-scan",
        action="store_true",
        help="Paksa semua halaman pakai deteksi scan-model (skip jalur text-layout).",
    )
    parser.add_argument(
        "--no-merge",
        action="store_true",
        help="Jangan gabungkan garis per-run; outputkan garis per-box teks.",
    )
    parser.add_argument(
        "--outside-shift",
        type=float,
        default=0.0,
        help="Geser garis ke kiri (lebih luar). Nilai positif = makin keluar.",
    )
    args = parser.parse_args()

    out, n = apply_scan_model_with_options(
        input_pdf=args.input,
        output_pdf=args.output,
        model_path=args.scan_model_file,
        zoom=args.zoom,
        force_scan=args.force_scan,
        no_merge=args.no_merge,
        outside_shift=args.outside_shift,
    )
    print(f"Selesai (scan-model): {out} | segments={n}")


if __name__ == "__main__":
    main()
