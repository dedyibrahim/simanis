import re
import os
import math
import argparse
import json
import random
from dataclasses import dataclass
import fitz

INPUT_PDF = "akta_test6.pdf"
OUTPUT_PDF = "akta_bergarisss lagi.pdf"
DEFAULT_MODEL_FILES = [
    "garis_local_model_bodyx_v9.json",
    "garis_local_model_bodyx_v8.json",
    "garis_local_model_bodyx_v7.json",
    "garis_local_model_bodyx_v5.json",
    "garis_local_model_bodyx_v3.json",
    "garis_local_model_bodyx_v1.json",
    "garis_local_model_v2.json",
    "garis_local_model.json",
]

# =========================
# PENGATURAN
# =========================
LEFT_OFFSET = 2         # garis vertikal mepet ke teks
LINE_WIDTH = 1.0        # ketebalan garis
LINE_COLOR = (1, 0, 0)  # warna merah (RGB 0..1 untuk PyMuPDF)
TOP_IGNORE = 35         # abaikan area header atas; cukup rendah agar lanjutan paragraf awal halaman tetap ikut
TOP_IGNORE_FIRST_PAGE = 90  # header halaman 1 biasanya lebih tinggi
BOTTOM_IGNORE = 50      # abaikan footer bawah
MIN_TEXT_LEN = 3
BODY_MAX_X0 = None      # isi angka (mis. 320) kalau mau paksa batas kanan body
MAX_INDENT_FROM_BASE = 70  # toleransi x0 dari margin kiri dominan per halaman
INDENT_TOL = 8          # toleransi perubahan indent (point)
LINE_GAP_BREAK = 16     # pemutus blok jika antarbaris terlalu renggang
MIN_SECTION_HEIGHT = 8  # tinggi minimum section agar tetap dianggap valid
ENABLE_START_END_CAPS = True  # aktifkan cap pembuka/penutup
ENABLE_LEADING_STUBS = True   # aktif: marker enum/dash dibuat stub lalu garis masuk ke body
CAP_SIDE = 1            # 1 = ke dalam (kanan), -1 = ke luar (kiri)
CAP_TOP_DX = 50         # panjang penutup atas arah horizontal
CAP_TOP_DY = -10         # kemiringan penutup atas (turun sedikit)
CAP_BOTTOM_DX = 50      # panjang penutup bawah arah horizontal
CAP_BOTTOM_DY = -10      # kemiringan penutup bawah (naik sedikit)
VALIDATION_EXTRA_INDENT = 30    # validasi cek baris lebih dalam dari batas normal
VALIDATION_MIN_COVERAGE = 0.98  # minimal persentase baris yang harus ter-cover
VALIDATION_MAX_UNCOVERED = 30   # toleransi jumlah baris belum tergaris (dokumen panjang)
VALIDATION_MAX_LONG_UNCOVERED = 8  # toleransi baris panjang belum tergaris
VALIDATION_LONG_TEXT_LEN = 35   # definisi "baris panjang"
VALIDATION_X_GAP_EXTRA = 40     # toleransi jarak x saat cocokkan baris vs garis
SEGMENT_MERGE_X_TOL = 2.5       # toleransi x untuk anggap segmen sejajar
SEGMENT_MERGE_GAP = 42          # jarak vertikal maksimum untuk gabung segmen sejajar
SEGMENT_SHORT_MAX_H = 16        # tinggi segmen pendek (indikasi stub/list item)
SEGMENT_SNAP_X_TOL = 22         # toleransi x untuk snap segmen pendek ke kolom tetangga
SEGMENT_SNAP_GAP = 48           # jarak vertikal maksimum saat snap segmen pendek
RAIL_BUCKET_X_TOL = 15          # toleransi bucket kolom kiri pada mode rail-stable
RAIL_JOIN_GAP = 24              # maksimum gap vertikal untuk menyambung rail

# numbering utama: 1.  2.  3.  atau A. B. C.
RE_MAIN_NUMBER = re.compile(r"^\s*(\d+\.|[A-Z]\.)\s+")
RE_ENUM_WITH_TEXT = re.compile(r"^\s*((\d+|[A-Za-z]|[IVXLCDMivxlcdm]+)[\.\)])\s+\S+")
RE_IGNORE_CENTER_SHORT = re.compile(r"^\s*(nomor|number)\b", re.I)
RE_DECORATIVE_DASH = re.compile(r"[-â€“â€”]{5,}")
RE_PASAL_ONLY = re.compile(r"^\s*pasal\s+\d+[a-z]?\s*$", re.I)
RE_DASH_BANNER_LINE = re.compile(r"^\s*[-\u2013\u2014]{5,}.+[-\u2013\u2014]{5,}\s*$")
RE_BAB_ONLY = re.compile(r"^\s*bab\s+[ivxlcdm0-9]+\s*$", re.I)
RE_DASH_MARKER_ONLY = re.compile(r"^\s*[-â€“â€”]{1,3}\s*$")
RE_DASH_SEPARATOR_ONLY = re.compile(r"^\s*[-\u2013\u2014_]{5,}\s*$")
# Item dash bisa ditulis "- Teks" atau "-Teks" (tanpa spasi),
# bahkan kadang "--Teks". Semua diperlakukan sebagai start item.
RE_DASH_WITH_TEXT = re.compile(r"^\s*[-â€“â€”]{1,3}\s*\S")
RE_PAGE_NUMBER_ONLY = re.compile(r"^\s*\d+\s*$")
RE_ENUM_MARKER_ONLY = re.compile(r"^\s*((\d+|[A-Za-z]|[IVXLCDMivxlcdm]+)[\.\)])\s*$")
RE_ENUM_TOKEN_ONLY = re.compile(r"^(\d+|[A-Za-z]|[IVXLCDMivxlcdm]+)[\.\)]$")


def normalize_dash_prefix(text: str) -> str:
    t = (text or "").lstrip()
    # Beberapa PDF menyisipkan pembuka "(", kutip, atau "|" sebelum "-".
    t = re.sub(r"^[\(\[\{<\"'`â€œâ€â€˜â€™|]+\s*", "", t)
    return t


def starts_dash_with_text(text: str) -> bool:
    t = normalize_dash_prefix(text)
    return bool(RE_DASH_WITH_TEXT.match(t))


def is_enum_token(word: str) -> bool:
    return bool(RE_ENUM_TOKEN_ONLY.match(word.strip()))


def is_dash_token(word: str) -> bool:
    return bool(RE_DASH_MARKER_ONLY.match(word.strip()))
def is_dash_separator_line(text: str) -> bool:
    return bool(RE_DASH_SEPARATOR_ONLY.match((text or "").strip()))



def is_dash_banner_line(text: str) -> bool:
    t = (text or "").strip()
    if not t:
        return False
    if not RE_DASH_BANNER_LINE.match(t):
        return False
    core = re.sub(r"[-\u2013\u2014_]+", " ", t)
    core = re.sub(r"\s+", " ", core).strip()
    return bool(re.search(r"[A-Za-z]", core))


def dominant_left_bucket(lines):
    if not lines:
        return None

    freq = {}
    for ln in lines:
        bucket = int(round(ln["x0"]))
        freq[bucket] = freq.get(bucket, 0) + 1

    if not freq:
        return None

    max_freq = max(freq.values())
    min_count = max(2, int(math.ceil(max_freq * 0.3)))
    candidate_buckets = [x for x, c in freq.items() if c >= min_count]
    if candidate_buckets:
        return sorted(candidate_buckets, key=lambda x: (-freq[x], x))[0]
    return sorted(freq.keys(), key=lambda x: (-freq[x], x))[0]


def first_body_x0_from_words(word_items, fallback_x0):
    """
    Ambil batas kiri isi teks.
    Jika baris diawali marker enum/dash (mis. "1." / "a." / "ii." / "-"),
    pakai x0 kata setelah marker.
    """
    if not word_items:
        return float(fallback_x0)

    items = sorted(word_items, key=lambda t: (t[0], t[1]))
    tokens = [str(t[2]).strip() for t in items]
    if not tokens:
        return float(items[0][0])

    start_idx = 0
    if is_enum_token(tokens[0]) or is_dash_token(tokens[0]):
        start_idx = 1

    if start_idx >= len(items):
        start_idx = 0

    i = start_idx
    while i < len(items):
        tok = str(items[i][2]).strip()
        compact = re.sub(r"[^0-9A-Za-z]+", "", tok)
        if compact:
            return float(items[i][0])
        i += 1

    return float(items[start_idx][0])


def merge_same_row_fragments(raw_lines, y_tol=1.2, max_gap=260.0):
    """
    Beberapa PDF memecah 1 baris jadi banyak fragmen kata di y yang sama.
    Gabungkan fragmen tersebut menjadi 1 logical line agar garis tidak bolong.
    """
    if not raw_lines:
        return []

    ordered = sorted(raw_lines, key=lambda z: (z["page"], z["y0"], z["x0"]))
    groups = []
    current = [ordered[0]]
    anchor = ordered[0]

    for ln in ordered[1:]:
        same_page = ln["page"] == anchor["page"]
        same_row = abs(ln["y0"] - anchor["y0"]) <= y_tol and abs(ln["y1"] - anchor["y1"]) <= (y_tol + 0.8)
        if same_page and same_row:
            current.append(ln)
        else:
            groups.append(current)
            current = [ln]
            anchor = ln

    if current:
        groups.append(current)

    merged = []
    for grp in groups:
        if len(grp) == 1:
            merged.append(grp[0])
            continue

        parts = sorted(grp, key=lambda z: z["x0"])
        gaps = [parts[i]["x0"] - parts[i - 1]["x1"] for i in range(1, len(parts))]

        # Jika jarak antarbagiannya terlalu jauh, anggap bukan 1 baris.
        if any(g > max_gap for g in gaps):
            merged.extend(parts)
            continue

        text = " ".join(p["text"].strip() for p in parts if p["text"].strip()).strip()
        if not text:
            merged.extend(parts)
            continue

        merged.append(
            {
                "text": text,
                "x0": min(p["x0"] for p in parts),
                "x0_raw": min(p.get("x0_raw", p["x0"]) for p in parts),
                "y0": min(p["y0"] for p in parts),
                "x1": max(p["x1"] for p in parts),
                "y1": max(p["y1"] for p in parts),
                "page": parts[0]["page"],
            }
        )

    merged.sort(key=lambda z: (z["page"], z["y0"], z["x0"]))
    return merged


@dataclass(frozen=True)
class LayoutConfig:
    max_indent_from_base: int
    indent_tol: int
    line_gap_break: int


@dataclass(frozen=True)
class ValidationResult:
    passed: bool
    coverage: float
    expected_count: int
    covered_count: int
    uncovered_count: int
    long_uncovered_count: int
    samples: tuple[str, ...]


@dataclass
class LocalLineModel:
    bias: float
    weights: dict[str, float]
    threshold: float = 0.5


def is_meaningful(text: str) -> bool:
    if not text:
        return False
    t = text.strip()
    if RE_DASH_MARKER_ONLY.match(t):
        return True
    if RE_DASH_SEPARATOR_ONLY.match(t):
        return True
    if len(t) < MIN_TEXT_LEN:
        return False
    t2 = re.sub(r"[-â€“â€”._\s]+", "", t)
    return bool(t2)


def is_centered(x0, x1, page_width, tol=70):
    cx = (x0 + x1) / 2
    return abs(cx - (page_width / 2)) <= tol


def is_decorative_center_heading(
    text: str,
    x0: float,
    x1: float,
    page_width: float,
    y0: float,
    page_no: int
) -> bool:
    # Heading dekoratif di tengah dokumen (contoh: KEGIATAN USAHA, Pasal 63)
    # di-skip pada semua halaman agar garis tidak muncul di tengah judul.
    top_limit = (TOP_IGNORE_FIRST_PAGE + 40) if page_no == 0 else (TOP_IGNORE + 70)
    if y0 > top_limit:
        return False

    if not is_centered(x0, x1, page_width):
        return False

    t = text.strip()
    if not t:
        return False

    # Contoh yang ingin di-skip:
    # "------------------ Usaha Tambahan ------------------"
    # "---------------------- Pasal 67 --------------------"
    core = re.sub(r"[-â€“â€”_]+", " ", t).strip()
    core = re.sub(r"\s+", " ", core)
    if not core:
        return False

    words = core.split()
    has_dash_both_sides = bool(re.match(r"^\s*[-â€“â€”]{5,}.+[-â€“â€”]{5,}\s*$", t))
    if has_dash_both_sides and len(words) <= 8:
        return True

    if RE_PASAL_ONLY.match(core) or RE_BAB_ONLY.match(core):
        return True

    return False


def extract_lines(page, cfg: LayoutConfig, extra_indent=0):
    data = page.get_text("dict")
    words = page.get_text("words")
    visible_x0 = {}
    line_words = {}
    for w in words:
        # (x0, y0, x1, y1, word, block_no, line_no, word_no)
        x0_w, _, x1_w, _, word, block_no, line_no, _ = w
        if not str(word).strip():
            continue
        key = (int(block_no), int(line_no))
        if key not in visible_x0 or x0_w < visible_x0[key]:
            visible_x0[key] = x0_w
        line_words.setdefault(key, []).append((float(x0_w), float(x1_w), str(word)))

    page_w = page.rect.width
    page_h = page.rect.height
    top_ignore = TOP_IGNORE_FIRST_PAGE if page.number == 0 else TOP_IGNORE
    raw_lines = []

    text_block_idx = -1
    for block in data["blocks"]:
        if block.get("type") != 0:
            continue
        text_block_idx += 1

        for line_idx, line in enumerate(block.get("lines", [])):
            spans = line.get("spans", [])
            if not spans:
                continue

            text = "".join(span.get("text", "") for span in spans).strip()
            if not is_meaningful(text):
                continue
            if is_dash_banner_line(text):
                continue
            if is_dash_banner_line(text):
                # Heading/banner dekoratif dash tengah tidak digaris.
                continue

            x0_bbox, y0, x1, y1 = line["bbox"]
            line_key = (text_block_idx, line_idx)
            x0_raw = visible_x0.get(line_key, x0_bbox)
            x0 = first_body_x0_from_words(line_words.get(line_key, []), x0_raw)

            if y0 < top_ignore:
                continue
            if y1 > page_h - BOTTOM_IGNORE:
                continue

            # Header judul halaman pertama (huruf kapital di area atas) tidak digaris.
            if page.number == 0 and y0 < (TOP_IGNORE_FIRST_PAGE + 130):
                alpha_only = re.sub(r"[^A-Za-z]", "", text)
                if alpha_only and alpha_only.upper() == alpha_only:
                    continue

            raw_lines.append({
                "text": text,
                "x0": x0,
                "x0_raw": float(x0_raw),
                "y0": y0,
                "x1": x1,
                "y1": y1,
                "page": page.number
            })

    if not raw_lines:
        return []
    raw_lines = merge_same_row_fragments(raw_lines)

    # cari margin kiri dominan agar bisa adaptif ke layout PDF berbeda.
    base_left = dominant_left_bucket(raw_lines)
    if base_left is None:
        return []

    dynamic_x_cutoff = base_left + cfg.max_indent_from_base + extra_indent
    x_cutoff = dynamic_x_cutoff if BODY_MAX_X0 is None else min(dynamic_x_cutoff, BODY_MAX_X0)

    lines = []
    for ln in raw_lines:
        text = ln["text"]
        x0, x1 = ln["x0"], ln["x1"]

        if x0 > x_cutoff:
            continue

        if is_decorative_center_heading(text, x0, x1, page_w, ln["y0"], ln["page"]):
            continue

        # abaikan judul tengah pendek
        if is_centered(x0, x1, page_w) and len(text) < 40:
            if RE_IGNORE_CENTER_SHORT.search(text) or text.isupper() or len(text.split()) <= 4:
                continue

        lines.append(ln)

    lines.sort(key=lambda z: (z["y0"], z["x0"]))
    return lines


def split_runs(lines, cfg: LayoutConfig):
    """
    Pecah baris menjadi run kecil berdasarkan:
    - awal nomor utama (1., 2., A., B.) => run baru
    - perubahan indent yang signifikan
    - jarak vertikal antarbaris yang terlalu renggang
    """
    if not lines:
        return []

    runs = []
    current = [lines[0]]

    for ln in lines[1:]:
        prev = current[-1]
        txt = ln["text"]

        curr_is_dash_only = is_dash_only_marker(txt)
        prev_is_dash_only = is_dash_only_marker(prev["text"])
        curr_is_dash_sep = is_dash_separator_line(txt)
        prev_is_dash_sep = is_dash_separator_line(prev["text"])
        starts_dash_with_text_flag = starts_dash_with_text(txt)

        # Separator "-----" selalu jadi pemisah blok.
        if curr_is_dash_sep:
            runs.append(current)
            current = [ln]
            continue

        # Marker "-" tunggal memulai blok baru, agar tidak menempel ke blok atas.
        if curr_is_dash_only:
            runs.append(current)
            current = [ln]
            continue

        # Baris "-teks" jangan otomatis memutus run.
        # Pada format akta, banyak paragraf formal memang diawali "-".
        # Pemutusan tetap ditangani oleh gap vertikal / indent / marker "-" tunggal.

        # Baris setelah separator/dash-only selalu jadi blok baru.
        if prev_is_dash_sep or prev_is_dash_only:
            runs.append(current)
            current = [ln]
            continue

        starts_enum_line = bool(RE_ENUM_WITH_TEXT.match(txt))
        prev_starts_enum_line = bool(RE_ENUM_WITH_TEXT.match(prev["text"]))
        same_page = ln["page"] == prev["page"]
        big_vertical_gap = same_page and (ln["y0"] - prev["y1"]) > cfg.line_gap_break
        indent_delta_prev = ln["x0"] - prev["x0"]
        outdent_changed = indent_delta_prev < -cfg.indent_tol
        deep_indent_changed = indent_delta_prev > (cfg.indent_tol + 6)
        continuation_after_enum = deep_indent_changed and prev_starts_enum_line

        if starts_enum_line or outdent_changed or big_vertical_gap:
            runs.append(current)
            current = [ln]
        elif deep_indent_changed and not continuation_after_enum:
            runs.append(current)
            current = [ln]
        else:
            current.append(ln)

    if current:
        runs.append(current)

    return runs


def filter_sections(sections):
    filtered = []

    for sec in sections:
        dash_only_section = all(is_dash_only_marker(x["text"]) for x in sec)
        dash_sep_section = all(is_dash_separator_line(x["text"]) for x in sec)
        if dash_sep_section:
            continue

        text_all = " ".join(x["text"] for x in sec).strip()
        if not dash_only_section and len(text_all) < 8:
            continue

        # Pertahankan enum satu-baris (a./b./1./I.) agar item list tidak
        # hilang garis; hanya buang jika hampir tanpa isi.
        if len(sec) == 1:
            t = sec[0]["text"].strip()
            if RE_ENUM_WITH_TEXT.match(t):
                body = strip_enum_prefix(t)
                compact_body = re.sub(r"[-â€“â€”\s\.,;:()\[\]/]+", "", body)
                if len(compact_body) < 4:
                    continue

        y0 = min(x["y0"] for x in sec)
        y1 = max(x["y1"] for x in sec)
        min_height = 4 if dash_only_section else MIN_SECTION_HEIGHT
        if (y1 - y0) < min_height:
            continue

        filtered.append(sec)

    return filtered


def is_dash_only_marker(text: str) -> bool:
    return bool(RE_DASH_MARKER_ONLY.match(text.strip()))


def strip_enum_prefix(text: str) -> str:
    return re.sub(r"^\s*((\d+|[A-Za-z]|[IVXLCDMivxlcdm]+)[\.\)])\s*", "", text).strip()


def pick_section_x0(ordered):
    first_text = ordered[0]["text"].strip() if ordered else ""
    if first_text and RE_ENUM_WITH_TEXT.match(first_text):
        # Untuk item enum (1./a./i.), garis utama mengikuti body text.
        # Posisi marker akan ditangani oleh stub pendek terpisah.
        if len(ordered) > 1:
            # Lebih stabil: body mengikuti indent lanjutan baris berikutnya.
            body_follow = [float(ln["x0"]) for ln in ordered[1:] if not is_dash_only_marker(ln["text"].strip())]
            if body_follow:
                return _median(body_follow)

        marker_x0 = float(ordered[0].get("x0_raw", ordered[0]["x0"]))
        body_x0 = float(ordered[0]["x0"])
        marker_gap = body_x0 - marker_x0
        if marker_gap >= max(6.0, INDENT_TOL - 1):
            return body_x0
        return body_x0

    # Gunakan x0 dominan dari baris konten (bukan marker nomor/dash)
    # agar garis tidak loncat-loncat pada list bertingkat.
    buckets = {}
    for ln in ordered:
        t = ln["text"].strip()
        if is_dash_only_marker(t) or is_dash_separator_line(t):
            continue

        body = strip_enum_prefix(t)
        compact = re.sub(r"[-â€“â€”\s\.,;:()\[\]/]+", "", body)
        if len(compact) < 4:
            continue

        b = int(round(ln["x0"]))
        buckets[b] = buckets.get(b, 0) + 1

    if not buckets:
        return min(x["x0"] for x in ordered)

    return float(sorted(buckets.keys(), key=lambda k: (-buckets[k], k))[0])


def compute_leading_marker_stub(ordered):
    """
    Jika baris pertama diawali marker enum/dash lalu ada lanjutan baris,
    gambar stub pendek di posisi marker (luar), lalu garis utama masuk ke body.
    """
    if not ENABLE_LEADING_STUBS:
        return None, False

    if len(ordered) <= 1:
        return None, False

    first = ordered[0]
    second = ordered[1]
    first_text = first["text"].strip()
    is_enum_line = bool(RE_ENUM_WITH_TEXT.match(first_text))
    is_dash_line = starts_dash_with_text(first_text)
    is_marker_line = is_dash_line or is_enum_line
    if not is_marker_line:
        return None, False

    marker_x0 = float(first.get("x0_raw", first["x0"]))
    body_x0 = float(first["x0"])
    marker_gap = body_x0 - marker_x0
    # Jika token words tidak memisah marker dengan baik, infer jarak dari
    # baris lanjutan agar tetap terbentuk pola stub + garis utama.
    if marker_gap < 4.0:
        marker_gap = max(marker_gap, float(second["x0"]) - marker_x0)
    if marker_gap < max(6.0, INDENT_TOL - 1):
        return None, False

    if (second["y0"] - first["y0"]) <= 2:
        return None, False

    stub = {
        "x": marker_x0 - LEFT_OFFSET,
        "y0": first["y0"] + 1,
        "y1": first["y1"] - 1,
    }
    if stub["y1"] <= stub["y0"]:
        return None, False

    return stub, True


def compute_section_geometry(section, trim_first_line_top=False):
    ordered = sorted(section, key=lambda z: (z["y0"], z["x0"]))
    first_text = ordered[0]["text"].strip() if ordered else ""
    starts_dash_with_text_flag = starts_dash_with_text(first_text)
    x = pick_section_x0(ordered) - LEFT_OFFSET
    y0 = min(x["y0"] for x in ordered) + 1
    y1 = max(x["y1"] for x in ordered) - 1
    lead_stub = None
    break_merge_before = False
    break_merge_after = False

    # Baris "-teks" tidak otomatis jadi barrier merge.
    # Ini agar garis bisa kontinyu mengikuti alur paragraf utama.

    lead_stub, shift_main_to_second = compute_leading_marker_stub(ordered)
    if shift_main_to_second:
        second = ordered[1]
        y0 = max(y0, second["y0"] + 1)
        break_merge_before = True
        # Item marker umumnya perlu putus antar-item agar bentuk seperti referensi.
        break_merge_after = True

    # Jika section diawali setelah marker "-" tunggal, jangan tarik garis dari
    # atas baris pertama; mulai dari baris berikutnya agar rapi.
    if trim_first_line_top and len(ordered) > 1:
        first = ordered[0]
        second = ordered[1]
        if (second["y0"] - first["y0"]) > 3:
            y0 = max(y0, second["y0"] + 1)
            break_merge_before = True

    if y1 <= y0:
        return None

    return {
        "x": x,
        "y0": y0,
        "y1": y1,
        "lead_stub": lead_stub,
        "break_merge_before": break_merge_before,
        "break_merge_after": break_merge_after,
    }


def draw_section_line(
    page,
    section,
    draw_start_cap=False,
    draw_end_cap=False,
    trim_first_line_top=False
):
    geom = compute_section_geometry(section, trim_first_line_top=trim_first_line_top)
    if geom is None:
        return None

    x, y0, y1 = geom["x"], geom["y0"], geom["y1"]
    lead_stub = geom.get("lead_stub")

    if lead_stub is not None:
        page.draw_line(
            p1=(lead_stub["x"], lead_stub["y0"]),
            p2=(lead_stub["x"], lead_stub["y1"]),
            color=LINE_COLOR,
            width=LINE_WIDTH
        )

    page.draw_line(
        p1=(x, y0),
        p2=(x, y1),
        color=LINE_COLOR,
        width=LINE_WIDTH
    )

    # pembuka atas: miring sedikit ke bawah (ke kiri agar tidak menabrak teks)
    if ENABLE_START_END_CAPS and draw_start_cap:
        page.draw_line(
            p1=(x, y0),
            p2=(x + (CAP_SIDE * CAP_TOP_DX), y0 + CAP_TOP_DY),
            color=LINE_COLOR,
            width=LINE_WIDTH
        )

    # penutup bawah: miring sedikit ke atas (ke kiri agar tidak menabrak teks)
    if ENABLE_START_END_CAPS and draw_end_cap:
        page.draw_line(
            p1=(x, y1),
            p2=(x + (CAP_SIDE * CAP_BOTTOM_DX), y1 - CAP_BOTTOM_DY),
            color=LINE_COLOR,
            width=LINE_WIDTH
        )

    return geom


def split_section_by_page(section):
    by_page = {}
    for ln in section:
        by_page.setdefault(ln["page"], []).append(ln)
    return by_page


def is_dash_only_section(section):
    return bool(section) and all(is_dash_only_marker(x["text"]) for x in section)


def has_dash_prefix_section(sections, idx, cfg: LayoutConfig):
    if idx <= 0:
        return False

    prev_sec = sections[idx - 1]
    curr_sec = sections[idx]
    if not is_dash_only_section(prev_sec) or not curr_sec:
        return False

    prev_last = max(prev_sec, key=lambda z: (z["page"], z["y1"]))
    curr_first = min(curr_sec, key=lambda z: (z["page"], z["y0"]))

    if prev_last["page"] != curr_first["page"]:
        return False

    return (curr_first["y0"] - prev_last["y0"]) <= (cfg.line_gap_break + 8)


def build_sections(doc, cfg: LayoutConfig, extra_indent=0):
    all_lines = []
    for page in doc:
        all_lines.extend(extract_lines(page, cfg, extra_indent=extra_indent))

    if not all_lines:
        return all_lines, []

    sections = split_runs(all_lines, cfg)
    sections = filter_sections(sections)
    return all_lines, sections


def plan_segments(sections, cfg: LayoutConfig):
    if not sections:
        return []

    segments = []
    for sec_idx, sec in enumerate(sections):
        parts = split_section_by_page(sec)
        pages = sorted(parts.keys())
        first_page = pages[0]
        has_dash_prefix = has_dash_prefix_section(sections, sec_idx, cfg)

        for pg_no in pages:
            part_lines = sorted(parts[pg_no], key=lambda z: (z["y0"], z["x0"]))
            trim_first_line_top = has_dash_prefix and pg_no == first_page
            geom = compute_section_geometry(part_lines, trim_first_line_top=trim_first_line_top)
            if geom is None:
                continue

            lead_stub = geom.get("lead_stub")
            if lead_stub is not None:
                segments.append(
                    {
                        "page": pg_no,
                        "x": lead_stub["x"],
                        "y0": lead_stub["y0"],
                        "y1": lead_stub["y1"],
                        "draw_start_cap": False,
                        "draw_end_cap": False,
                        "is_stub": True,
                        "break_merge_before": True,
                        "break_merge_after": True,
                    }
                )

            seg = {
                "page": pg_no,
                "x": geom["x"],
                "y0": geom["y0"],
                "y1": geom["y1"],
                "draw_start_cap": False,
                "draw_end_cap": False,
                "is_stub": False,
                "break_merge_before": bool(geom.get("break_merge_before", False)),
                "break_merge_after": bool(geom.get("break_merge_after", False)),
            }
            segments.append(seg)

    return postprocess_segments(segments)


def segment_height(seg):
    return float(seg["y1"] - seg["y0"])


def snap_short_segments_to_nearby_column(page_segments):
    items = [dict(seg) for seg in page_segments]
    if not items:
        return items

    for i, seg in enumerate(items):
        if bool(seg.get("is_stub", False)):
            continue
        if segment_height(seg) > SEGMENT_SHORT_MAX_H:
            continue

        best_idx = None
        best_key = None
        for j, other in enumerate(items):
            if i == j:
                continue
            if bool(other.get("is_stub", False)):
                continue
            if segment_height(other) <= SEGMENT_SHORT_MAX_H:
                continue

            dx = abs(float(seg["x"]) - float(other["x"]))
            if dx > SEGMENT_SNAP_X_TOL:
                continue

            vgap = max(
                0.0,
                max(float(other["y0"]) - float(seg["y1"]), float(seg["y0"]) - float(other["y1"]))
            )
            if vgap > SEGMENT_SNAP_GAP:
                continue

            key = (vgap, dx, -segment_height(other))
            if best_key is None or key < best_key:
                best_key = key
                best_idx = j

        if best_idx is not None:
            seg["x"] = float(items[best_idx]["x"])

    return items


def merge_aligned_segments_on_page(page_segments):
    if not page_segments:
        return []

    stubs = [dict(seg) for seg in page_segments if bool(seg.get("is_stub", False))]
    work = [dict(seg) for seg in page_segments if not bool(seg.get("is_stub", False))]
    if not work:
        stubs.sort(key=lambda s: (float(s["y0"]), float(s["x"])))
        return stubs

    # Kelompokkan berdasarkan kolom x yang nyaris sama.
    columns = []
    for seg in sorted(work, key=lambda s: (float(s["x"]), float(s["y0"]))):
        assigned = False
        for col in columns:
            if abs(float(seg["x"]) - float(col["x_ref"])) <= SEGMENT_MERGE_X_TOL:
                col["items"].append(dict(seg))
                col["x_ref"] = (float(col["x_ref"]) * (len(col["items"]) - 1) + float(seg["x"])) / len(col["items"])
                assigned = True
                break
        if not assigned:
            columns.append({"x_ref": float(seg["x"]), "items": [dict(seg)]})

    out = []
    for col in columns:
        items = sorted(col["items"], key=lambda s: (float(s["y0"]), float(s["y1"])))
        cur = dict(items[0])
        for seg in items[1:]:
            gap = max(0.0, float(seg["y0"]) - float(cur["y1"]))
            blocked = bool(seg.get("break_merge_before", False)) or bool(cur.get("break_merge_after", False))
            if gap <= SEGMENT_MERGE_GAP and not blocked:
                cur["y0"] = min(float(cur["y0"]), float(seg["y0"]))
                cur["y1"] = max(float(cur["y1"]), float(seg["y1"]))
                cur["x"] = (float(cur["x"]) + float(seg["x"])) / 2.0
                cur["draw_start_cap"] = bool(cur.get("draw_start_cap")) or bool(seg.get("draw_start_cap"))
                cur["draw_end_cap"] = bool(cur.get("draw_end_cap")) or bool(seg.get("draw_end_cap"))
                cur["break_merge_after"] = bool(cur.get("break_merge_after")) or bool(seg.get("break_merge_after"))
            else:
                out.append(cur)
                cur = dict(seg)
        out.append(cur)

    out.extend(stubs)
    out.sort(key=lambda s: (float(s["y0"]), float(s["x"])))
    return out


def postprocess_segments(segments):
    if not segments:
        return []

    by_page = {}
    for seg in segments:
        by_page.setdefault(int(seg["page"]), []).append(seg)

    out = []
    for pg in sorted(by_page.keys()):
        snapped = snap_short_segments_to_nearby_column(by_page[pg])
        merged = merge_aligned_segments_on_page(snapped)
        merged = apply_page_caps(merged)
        out.extend(merged)

    out.sort(key=lambda s: (int(s["page"]), float(s["y0"]), float(s["x"])))
    return out


def apply_page_caps(page_segments):
    if not page_segments:
        return []

    items = [dict(seg) for seg in page_segments]
    for seg in items:
        seg["draw_start_cap"] = False
        seg["draw_end_cap"] = False

    candidates = [i for i, seg in enumerate(items) if not bool(seg.get("is_stub", False))]
    if not candidates:
        candidates = list(range(len(items)))
    if not candidates:
        return items

    first_idx = min(candidates, key=lambda i: (float(items[i]["y0"]), float(items[i]["x"])))
    last_idx = max(candidates, key=lambda i: (float(items[i]["y1"]), -float(items[i]["x"])))

    items[first_idx]["draw_start_cap"] = True
    items[last_idx]["draw_end_cap"] = True
    return items


def draw_segments(doc, segments):
    for seg in segments:
        page = doc[seg["page"]]
        x, y0, y1 = seg["x"], seg["y0"], seg["y1"]

        page.draw_line(
            p1=(x, y0),
            p2=(x, y1),
            color=LINE_COLOR,
            width=LINE_WIDTH
        )

        if ENABLE_START_END_CAPS and seg["draw_start_cap"]:
            page.draw_line(
                p1=(x, y0),
                p2=(x + (CAP_SIDE * CAP_TOP_DX), y0 + CAP_TOP_DY),
                color=LINE_COLOR,
                width=LINE_WIDTH
            )

        if ENABLE_START_END_CAPS and seg["draw_end_cap"]:
            page.draw_line(
                p1=(x, y1),
                p2=(x + (CAP_SIDE * CAP_BOTTOM_DX), y1 - CAP_BOTTOM_DY),
                color=LINE_COLOR,
                width=LINE_WIDTH
            )


def is_expected_line_for_validation(line):
    text = line["text"].strip()
    if RE_PAGE_NUMBER_ONLY.match(text):
        return False
    if RE_ENUM_MARKER_ONLY.match(text):
        return False
    if is_dash_only_marker(text):
        return False
    if is_dash_separator_line(text):
        return False
    if starts_dash_with_text(text):
        return False

    if RE_ENUM_WITH_TEXT.match(text):
        body = strip_enum_prefix(text)
        compact_body = re.sub(r"[-â€“â€”\s\.,;:()\[\]/]+", "", body)
        if len(compact_body) < 55:
            return False

    # Abaikan noise pendek/simbolik yang sering muncul di dokumen panjang
    compact = re.sub(r"[\s\-\â€“\â€”\.\,\;\:\(\)\[\]/]+", "", text)
    if len(compact) <= 2:
        return False
    if len(text.split()) == 1 and len(compact) <= 6:
        return False

    return True


def is_line_covered(line, segments_on_page, cfg: LayoutConfig):
    if not segments_on_page:
        return False

    y_mid = (line["y0"] + line["y1"]) / 2.0
    x_gap_max = cfg.max_indent_from_base + VALIDATION_X_GAP_EXTRA

    for seg in segments_on_page:
        if y_mid < (seg["y0"] - 1) or y_mid > (seg["y1"] + 1):
            continue

        dx = line["x0"] - seg["x"]
        # Toleransi kiri dibuat lebih longgar karena garis bisa sengaja
        # ditempatkan sedikit lebih dalam dari marker enum (I./1./a./ii.).
        if -90 <= dx <= x_gap_max:
            return True

    return False


def evaluate_layout(doc, cfg: LayoutConfig):
    _, sections = build_sections(doc, cfg, extra_indent=0)
    segments = plan_segments(sections, cfg)
    segments_by_page = {}
    for seg in segments:
        segments_by_page.setdefault(seg["page"], []).append(seg)

    expected_lines = []
    for page in doc:
        lines = extract_lines(page, cfg, extra_indent=VALIDATION_EXTRA_INDENT)
        for ln in lines:
            if is_expected_line_for_validation(ln):
                expected_lines.append(ln)

    if not expected_lines:
        return ValidationResult(
            passed=True,
            coverage=1.0,
            expected_count=0,
            covered_count=0,
            uncovered_count=0,
            long_uncovered_count=0,
            samples=tuple(),
        )

    covered_count = 0
    uncovered = []
    for ln in expected_lines:
        if is_line_covered(ln, segments_by_page.get(ln["page"], []), cfg):
            covered_count += 1
        else:
            uncovered.append(ln)

    expected_count = len(expected_lines)
    uncovered_count = len(uncovered)
    coverage = covered_count / float(expected_count)
    long_uncovered_count = sum(1 for ln in uncovered if len(ln["text"].strip()) >= VALIDATION_LONG_TEXT_LEN)

    samples = tuple(
        f"p{ln['page'] + 1}: {ln['text'][:90]}"
        for ln in uncovered[:6]
    )

    # Toleransi absolut + proporsional agar tetap ketat di dokumen pendek
    # namun tidak false-fail di dokumen sangat panjang.
    max_uncovered_allowed = max(
        VALIDATION_MAX_UNCOVERED,
        int(math.ceil(expected_count * 0.02))
    )
    max_long_uncovered_allowed = max(
        VALIDATION_MAX_LONG_UNCOVERED,
        int(math.ceil(expected_count * 0.005))
    )

    passed = (
        coverage >= VALIDATION_MIN_COVERAGE
        and uncovered_count <= max_uncovered_allowed
        and long_uncovered_count <= max_long_uncovered_allowed
    )

    return ValidationResult(
        passed=passed,
        coverage=coverage,
        expected_count=expected_count,
        covered_count=covered_count,
        uncovered_count=uncovered_count,
        long_uncovered_count=long_uncovered_count,
        samples=samples,
    )


def iter_candidate_configs():
    max_indent_candidates = [MAX_INDENT_FROM_BASE, 80, 90, 105, 120, 140, 170, 200, 230]
    indent_tol_candidates = sorted(set([INDENT_TOL, max(5, INDENT_TOL - 2), INDENT_TOL + 2, INDENT_TOL + 4]))
    line_gap_candidates = sorted(set([LINE_GAP_BREAK, LINE_GAP_BREAK + 3, LINE_GAP_BREAK + 6, LINE_GAP_BREAK + 10]))

    seen = set()
    for max_indent in max_indent_candidates:
        for indent_tol in indent_tol_candidates:
            for line_gap in line_gap_candidates:
                cfg = LayoutConfig(
                    max_indent_from_base=max_indent,
                    indent_tol=indent_tol,
                    line_gap_break=line_gap,
                )
                key = (cfg.max_indent_from_base, cfg.indent_tol, cfg.line_gap_break)
                if key in seen:
                    continue
                seen.add(key)
                yield cfg


def pick_best_config(doc):
    best_cfg = None
    best_result = None

    for cfg in iter_candidate_configs():
        result = evaluate_layout(doc, cfg)

        if best_result is None:
            best_cfg, best_result = cfg, result
        else:
            best_key = (
                best_result.passed,
                best_result.coverage,
                -best_result.uncovered_count,
                -best_result.long_uncovered_count,
            )
            curr_key = (
                result.passed,
                result.coverage,
                -result.uncovered_count,
                -result.long_uncovered_count,
            )
            if curr_key > best_key:
                best_cfg, best_result = cfg, result

        if result.passed:
            return cfg, result

    return best_cfg, best_result


def normalize_line_text(text: str) -> str:
    t = re.sub(r"\s+", " ", text.strip().lower())
    return t


def line_key(line):
    return (
        int(line["page"]),
        round(float(line["x0"]), 2),
        round(float(line["y0"]), 2),
        normalize_line_text(line["text"]),
    )


def extract_candidate_lines(page):
    data = page.get_text("dict")
    words = page.get_text("words")
    visible_x0 = {}
    line_words = {}
    for w in words:
        x0_w, _, x1_w, _, word, block_no, line_no, _ = w
        if not str(word).strip():
            continue
        key = (int(block_no), int(line_no))
        if key not in visible_x0 or x0_w < visible_x0[key]:
            visible_x0[key] = x0_w
        line_words.setdefault(key, []).append((float(x0_w), float(x1_w), str(word)))

    page_h = page.rect.height
    page_w = page.rect.width
    top_ignore = TOP_IGNORE_FIRST_PAGE if page.number == 0 else TOP_IGNORE

    raw_lines = []
    text_block_idx = -1
    for block in data["blocks"]:
        if block.get("type") != 0:
            continue
        text_block_idx += 1

        for line_idx, line in enumerate(block.get("lines", [])):
            spans = line.get("spans", [])
            if not spans:
                continue

            text = "".join(span.get("text", "") for span in spans).strip()
            if not is_meaningful(text):
                continue

            x0_bbox, y0, x1, y1 = line["bbox"]
            line_key = (text_block_idx, line_idx)
            x0_raw = visible_x0.get(line_key, x0_bbox)
            x0 = first_body_x0_from_words(line_words.get(line_key, []), x0_raw)

            if y0 < top_ignore:
                continue
            if y1 > page_h - BOTTOM_IGNORE:
                continue

            # Header judul halaman pertama tidak jadi kandidat.
            if page.number == 0 and y0 < (TOP_IGNORE_FIRST_PAGE + 130):
                alpha_only = re.sub(r"[^A-Za-z]", "", text)
                if alpha_only and alpha_only.upper() == alpha_only:
                    continue

            raw_lines.append(
                {
                    "text": text,
                    "x0": float(x0),
                    "x0_raw": float(x0_raw),
                    "y0": float(y0),
                    "x1": float(x1),
                    "y1": float(y1),
                    "page": page.number,
                    "page_w": float(page_w),
                }
            )

    raw_lines.sort(key=lambda z: (z["y0"], z["x0"]))
    raw_lines = merge_same_row_fragments(raw_lines)

    return raw_lines


def featurize_line(line, prev_line=None, next_line=None):
    text = line["text"].strip()
    lower = text.lower()
    page_w = max(1.0, line.get("page_w", 1.0))
    width = max(0.1, line["x1"] - line["x0"])

    feats = {
        "x0_rel": line["x0"] / page_w,
        "x1_rel": line["x1"] / page_w,
        "width_rel": width / page_w,
        "len_rel": min(len(text), 140) / 140.0,
        "word_rel": min(len(text.split()), 30) / 30.0,
        "is_dash_only": 1.0 if is_dash_only_marker(text) else 0.0,
        "is_enum_only": 1.0 if RE_ENUM_MARKER_ONLY.match(text) else 0.0,
        "starts_main_number": 1.0 if RE_MAIN_NUMBER.match(text) else 0.0,
        "is_page_number": 1.0 if RE_PAGE_NUMBER_ONLY.match(text) else 0.0,
        "is_centered": 1.0 if is_centered(line["x0"], line["x1"], page_w) else 0.0,
        "is_upper": 1.0 if (text.isupper() and any(c.isalpha() for c in text)) else 0.0,
        "has_colon": 1.0 if ":" in text else 0.0,
        "has_semicolon": 1.0 if ";" in text else 0.0,
        "starts_dash_char": 1.0 if starts_dash_with_text(text) else 0.0,
    }

    keywords = [
        "pasal",
        "bab",
        "nomor",
        "notaris",
        "akta",
        "wib",
        "kecamatan",
        "kelurahan",
        "rukun",
        "kartu",
        "penduduk",
        "telepon",
        "fax",
    ]
    for kw in keywords:
        feats[f"kw_{kw}"] = 1.0 if kw in lower else 0.0

    if prev_line is None:
        feats["no_prev"] = 1.0
    else:
        feats["prev_gap"] = min(2.0, max(0.0, (line["y0"] - prev_line["y1"]) / 40.0))
        feats["prev_indent"] = min(2.0, abs(line["x0"] - prev_line["x0"]) / 100.0)
        feats["prev_dash_only"] = 1.0 if is_dash_only_marker(prev_line["text"]) else 0.0

    if next_line is None:
        feats["no_next"] = 1.0
    else:
        feats["next_gap"] = min(2.0, max(0.0, (next_line["y0"] - line["y1"]) / 40.0))
        feats["next_indent"] = min(2.0, abs(next_line["x0"] - line["x0"]) / 100.0)

    return feats


def sigmoid(z):
    z = max(-30.0, min(30.0, z))
    return 1.0 / (1.0 + math.exp(-z))


def score_features(model: LocalLineModel, features):
    z = model.bias
    for k, v in features.items():
        z += model.weights.get(k, 0.0) * float(v)
    return sigmoid(z)


def train_linear_model(samples, epochs=20, lr=0.08, l2=1e-5, seed=42, threshold=0.5):
    if not samples:
        raise RuntimeError("Data training kosong.")

    rnd = random.Random(seed)
    positives = [s for s in samples if s[1] == 1]
    negatives = [s for s in samples if s[1] == 0]
    if not positives or not negatives:
        raise RuntimeError("Data training perlu label positif dan negatif.")

    max_neg = min(len(negatives), max(len(positives) * 3, 200))
    train_samples = positives + rnd.sample(negatives, max_neg)

    weights = {}
    bias = 0.0

    for _ in range(max(1, epochs)):
        rnd.shuffle(train_samples)
        for features, label in train_samples:
            p = sigmoid(bias + sum(weights.get(k, 0.0) * v for k, v in features.items()))
            g = p - float(label)

            bias -= lr * g
            for k, v in features.items():
                w = weights.get(k, 0.0)
                w -= lr * (g * v + l2 * w)
                if abs(w) < 1e-10:
                    if k in weights:
                        del weights[k]
                else:
                    weights[k] = w

    model = LocalLineModel(bias=bias, weights=weights, threshold=threshold)
    return model, len(positives), len(negatives), len(train_samples)


def save_local_model(path, model: LocalLineModel, meta=None):
    payload = {
        "version": 1,
        "bias": model.bias,
        "threshold": model.threshold,
        "weights": model.weights,
        "meta": meta or {},
    }
    with open(path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)


def load_local_model(path):
    if not os.path.exists(path):
        raise RuntimeError(f"File model tidak ditemukan: {path}")
    with open(path, "r", encoding="utf-8") as f:
        payload = json.load(f)
    return LocalLineModel(
        bias=float(payload.get("bias", 0.0)),
        threshold=float(payload.get("threshold", 0.5)),
        weights={k: float(v) for k, v in payload.get("weights", {}).items()},
    )


def evaluate_model_accuracy(samples, model: LocalLineModel):
    if not samples:
        return 0.0
    correct = 0
    for feats, label in samples:
        p = score_features(model, feats)
        pred = 1 if p >= model.threshold else 0
        if pred == int(label):
            correct += 1
    return correct / float(len(samples))


def collect_training_samples_from_pdf(pdf_path):
    doc = fitz.open(pdf_path)
    try:
        cfg, _ = pick_best_config(doc)
        if cfg is None:
            cfg = LayoutConfig(
                max_indent_from_base=MAX_INDENT_FROM_BASE,
                indent_tol=INDENT_TOL,
                line_gap_break=LINE_GAP_BREAK,
            )

        teacher_keys = set()
        candidates_by_page = {}

        for page in doc:
            page_candidates = extract_candidate_lines(page)
            candidates_by_page[page.number] = page_candidates

            teacher_lines = extract_lines(page, cfg, extra_indent=0)
            for ln in teacher_lines:
                teacher_keys.add(line_key(ln))

        samples = []
        for page_no in sorted(candidates_by_page.keys()):
            lines = candidates_by_page[page_no]
            for idx, ln in enumerate(lines):
                prev_ln = lines[idx - 1] if idx > 0 else None
                next_ln = lines[idx + 1] if idx + 1 < len(lines) else None
                feats = featurize_line(ln, prev_ln, next_ln)
                label = 1 if line_key(ln) in teacher_keys else 0
                samples.append((feats, label))

        return samples
    finally:
        doc.close()


def train_local_model_from_pdfs(pdf_paths, model_path, epochs=20, lr=0.08, threshold=0.5, seed=42):
    all_samples = []
    used_paths = []
    for p in pdf_paths:
        if not os.path.exists(p):
            continue
        used_paths.append(p)
        all_samples.extend(collect_training_samples_from_pdf(p))

    if not all_samples:
        raise RuntimeError("Tidak ada sample training. Pastikan path PDF benar.")

    model, pos_count, neg_count, train_count = train_linear_model(
        all_samples,
        epochs=epochs,
        lr=lr,
        seed=seed,
        threshold=threshold,
    )
    acc = evaluate_model_accuracy(all_samples, model)

    meta = {
        "train_files": used_paths,
        "samples_total": len(all_samples),
        "samples_pos": pos_count,
        "samples_neg": neg_count,
        "samples_used_for_optimization": train_count,
        "epochs": epochs,
        "lr": lr,
        "threshold": threshold,
        "train_accuracy_on_samples": acc,
    }
    save_local_model(model_path, model, meta=meta)
    return model, meta


def pick_lines_with_model(doc, model: LocalLineModel, threshold=None):
    th = model.threshold if threshold is None else threshold
    selected = []

    for page in doc:
        lines = extract_candidate_lines(page)
        if not lines:
            continue

        probs = []
        for idx, ln in enumerate(lines):
            prev_ln = lines[idx - 1] if idx > 0 else None
            next_ln = lines[idx + 1] if idx + 1 < len(lines) else None
            feats = featurize_line(ln, prev_ln, next_ln)
            p = score_features(model, feats)
            probs.append(p)

        flags = [p >= th for p in probs]
        # smoothing ringan untuk mengurangi line putus tipis
        soft_th = max(0.05, th - 0.07)
        for i, p in enumerate(probs):
            if flags[i] or p < soft_th:
                continue
            prev_on = i > 0 and flags[i - 1] and abs(lines[i]["x0"] - lines[i - 1]["x0"]) <= (INDENT_TOL + 8)
            next_on = (i + 1 < len(lines)) and flags[i + 1] and abs(lines[i]["x0"] - lines[i + 1]["x0"]) <= (INDENT_TOL + 8)
            if prev_on or next_on:
                flags[i] = True

        # Hard-keep: baris pemutus struktur tetap dipakai walau skor model rendah.
        # Ini mencegah garis nyambung salah pada item "-" / "I./1./a.".
        for i, ln in enumerate(lines):
            if flags[i]:
                continue
            txt = ln["text"].strip()
            if not txt:
                continue
            if is_dash_banner_line(txt):
                continue
            if is_dash_only_marker(txt) or starts_dash_with_text(txt) or RE_ENUM_WITH_TEXT.match(txt):
                flags[i] = True

        for ln, keep in zip(lines, flags):
            if keep:
                selected.append(ln)

    selected.sort(key=lambda z: (z["page"], z["y0"], z["x0"]))
    return selected


def evaluate_segments_vs_expected(doc, cfg: LayoutConfig, segments):
    segments_by_page = {}
    for seg in segments:
        segments_by_page.setdefault(seg["page"], []).append(seg)

    expected_lines = []
    for page in doc:
        lines = extract_lines(page, cfg, extra_indent=VALIDATION_EXTRA_INDENT)
        for ln in lines:
            if is_expected_line_for_validation(ln):
                expected_lines.append(ln)

    if not expected_lines:
        return ValidationResult(True, 1.0, 0, 0, 0, 0, tuple())

    covered_count = 0
    uncovered = []
    for ln in expected_lines:
        if is_line_covered(ln, segments_by_page.get(ln["page"], []), cfg):
            covered_count += 1
        else:
            uncovered.append(ln)

    expected_count = len(expected_lines)
    uncovered_count = len(uncovered)
    coverage = covered_count / float(expected_count)
    long_uncovered_count = sum(1 for ln in uncovered if len(ln["text"].strip()) >= VALIDATION_LONG_TEXT_LEN)
    samples = tuple(f"p{ln['page'] + 1}: {ln['text'][:90]}" for ln in uncovered[:6])

    max_uncovered_allowed = max(VALIDATION_MAX_UNCOVERED, int(math.ceil(expected_count * 0.02)))
    max_long_uncovered_allowed = max(VALIDATION_MAX_LONG_UNCOVERED, int(math.ceil(expected_count * 0.005)))

    passed = (
        coverage >= VALIDATION_MIN_COVERAGE
        and uncovered_count <= max_uncovered_allowed
        and long_uncovered_count <= max_long_uncovered_allowed
    )
    return ValidationResult(
        passed=passed,
        coverage=coverage,
        expected_count=expected_count,
        covered_count=covered_count,
        uncovered_count=uncovered_count,
        long_uncovered_count=long_uncovered_count,
        samples=samples,
    )


def model_threshold_candidates(model: LocalLineModel, override_threshold=None):
    base_th = model.threshold if override_threshold is None else override_threshold
    if override_threshold is not None:
        return [max(0.05, min(0.95, base_th))]
    return sorted(
        set(
            [
                max(0.05, base_th - 0.1),
                max(0.05, base_th - 0.05),
                base_th,
                min(0.95, base_th + 0.05),
                min(0.95, base_th + 0.1),
            ]
        )
    )


def build_segments_from_selected_lines(doc, selected_lines, cfg: LayoutConfig):
    sections = filter_sections(split_runs(selected_lines, cfg))
    segments = plan_segments(sections, cfg)
    report = evaluate_segments_vs_expected(doc, cfg, segments)
    return sections, segments, report


def compute_dash_cross_penalty(selected_lines, segments, cfg: LayoutConfig):
    by_page = {}
    for seg in segments:
        by_page.setdefault(int(seg["page"]), []).append(seg)

    penalty = 0
    for ln in selected_lines:
        text = ln["text"].strip()
        if not starts_dash_with_text(text):
            continue

        y_mid = (ln["y0"] + ln["y1"]) / 2.0
        page_segments = by_page.get(int(ln["page"]), [])
        best = None
        best_dx = None
        for seg in page_segments:
            if bool(seg.get("is_stub", False)):
                continue
            if y_mid < (float(seg["y0"]) - 1) or y_mid > (float(seg["y1"]) + 1):
                continue
            dx = ln["x0"] - float(seg["x"])
            if dx < -90 or dx > (cfg.max_indent_from_base + VALIDATION_X_GAP_EXTRA):
                continue
            adx = abs(dx)
            if best is None or adx < best_dx:
                best = seg
                best_dx = adx

        if best is not None and float(best["y0"]) < (ln["y0"] - 2):
            penalty += 1

    return penalty


def compute_segment_noise_penalty(segments):
    non_stub = [s for s in segments if not bool(s.get("is_stub", False))]
    stub_count = sum(1 for s in segments if bool(s.get("is_stub", False)))
    short_non_stub = sum(1 for s in non_stub if segment_height(s) <= (SEGMENT_SHORT_MAX_H + 2))

    by_page = {}
    for seg in non_stub:
        by_page.setdefault(int(seg["page"]), []).append(seg)

    x_noise = 0
    for _, segs in by_page.items():
        buckets = {int(round(float(s["x"]))) for s in segs}
        x_noise += max(0, len(buckets) - 5)

    return short_non_stub, x_noise, len(non_stub), stub_count


def compute_enum_anchor_penalty(selected_lines, segments):
    by_page = {}
    for seg in segments:
        by_page.setdefault(int(seg["page"]), []).append(seg)

    penalty = 0
    for ln in selected_lines:
        text = ln["text"].strip()
        if not RE_ENUM_WITH_TEXT.match(text):
            continue

        y_mid = (ln["y0"] + ln["y1"]) / 2.0
        page_segments = by_page.get(int(ln["page"]), [])
        best = None
        best_dx = None
        for seg in page_segments:
            if bool(seg.get("is_stub", False)):
                continue
            if y_mid < (float(seg["y0"]) - 1) or y_mid > (float(seg["y1"]) + 1):
                continue
            dx = ln["x0_raw"] - float(seg["x"])
            if dx < -90 or dx > (MAX_INDENT_FROM_BASE + VALIDATION_X_GAP_EXTRA):
                continue
            adx = abs(dx)
            if best is None or adx < best_dx:
                best = seg
                best_dx = adx

        if best is None:
            continue

        target_x = float(ln["x0_raw"]) - LEFT_OFFSET
        if abs(float(best["x"]) - target_x) > 5.0:
            penalty += 1

    return penalty


def candidate_rank_key(report: ValidationResult, dash_cross_penalty, enum_anchor_penalty, short_penalty, x_noise, segment_count, stub_count):
    return (
        report.passed,
        report.coverage,
        -report.uncovered_count,
        -report.long_uncovered_count,
        -dash_cross_penalty,
        -enum_anchor_penalty,
        stub_count,
        -short_penalty,
        -x_noise,
        -segment_count,
    )


def combine_selected_lines_vote(selected_lines_per_model, min_votes=2):
    if not selected_lines_per_model:
        return []

    vote_count = {}
    line_map = {}
    for selected in selected_lines_per_model:
        seen = set()
        for ln in selected:
            k = line_key(ln)
            if k in seen:
                continue
            seen.add(k)
            vote_count[k] = vote_count.get(k, 0) + 1
            if k not in line_map:
                line_map[k] = ln

    out = []
    for k, cnt in vote_count.items():
        ln = line_map[k]
        txt = ln["text"].strip()
        structural = is_dash_only_marker(txt) or starts_dash_with_text(txt) or bool(RE_ENUM_WITH_TEXT.match(txt))
        if cnt >= min_votes or structural:
            out.append(ln)

    out.sort(key=lambda z: (z["page"], z["y0"], z["x0"]))
    return out


def _median(values):
    if not values:
        return 0.0
    vals = sorted(float(v) for v in values)
    n = len(vals)
    mid = n // 2
    if n % 2 == 1:
        return vals[mid]
    return (vals[mid - 1] + vals[mid]) / 2.0


def rail_line_anchor_x(line):
    text = line["text"].strip()
    x_raw = float(line.get("x0_raw", line["x0"]))
    x_body = float(line["x0"])
    if is_dash_only_marker(text):
        return x_raw
    if starts_dash_with_text(text):
        return x_raw
    if RE_ENUM_WITH_TEXT.match(text):
        return x_raw
    return x_body


def cluster_lines_by_indent(lines, x_tol):
    clusters = []
    ordered = sorted(lines, key=lambda z: (rail_line_anchor_x(z), z["y0"], z["x0"]))
    for ln in ordered:
        ax = rail_line_anchor_x(ln)
        best_idx = None
        best_dx = None
        for i, col in enumerate(clusters):
            dx = abs(ax - col["x_ref"])
            if dx <= x_tol and (best_dx is None or dx < best_dx):
                best_idx = i
                best_dx = dx

        if best_idx is None:
            clusters.append({"x_ref": ax, "lines": [ln]})
        else:
            col = clusters[best_idx]
            col["lines"].append(ln)
            count = float(len(col["lines"]))
            col["x_ref"] = ((col["x_ref"] * (count - 1.0)) + ax) / count

    for col in clusters:
        col["x_ref"] = _median(rail_line_anchor_x(ln) for ln in col["lines"])
        col["lines"].sort(key=lambda z: (z["y0"], z["x0"]))

    clusters.sort(key=lambda c: c["x_ref"])
    return clusters


def _rail_barrier_before(prev_line, cur_line):
    prev_text = prev_line["text"].strip()
    cur_text = cur_line["text"].strip()
    if is_dash_separator_line(prev_text) or is_dash_separator_line(cur_text):
        return True
    if is_dash_only_marker(prev_text):
        return True
    if is_dash_only_marker(cur_text):
        return True
    if RE_ENUM_WITH_TEXT.match(cur_text):
        return True
    return False


def _make_rail_segment(page_no, run_lines):
    if not run_lines:
        return None

    y0 = min(float(ln["y0"]) for ln in run_lines) + 1.0
    y1 = max(float(ln["y1"]) for ln in run_lines) - 1.0
    if y1 <= y0:
        return None

    structural_anchors = []
    for ln in run_lines:
        t = ln["text"].strip()
        if RE_ENUM_WITH_TEXT.match(t) or starts_dash_with_text(t) or is_dash_only_marker(t):
            structural_anchors.append(rail_line_anchor_x(ln))

    if structural_anchors:
        x_anchor = _median(structural_anchors)
    else:
        x_anchor = _median(rail_line_anchor_x(ln) for ln in run_lines)

    x = x_anchor - LEFT_OFFSET
    first_text = run_lines[0]["text"].strip()
    last_text = run_lines[-1]["text"].strip()
    start_barrier = is_dash_only_marker(first_text) or bool(RE_ENUM_WITH_TEXT.match(first_text))
    end_barrier = is_dash_only_marker(last_text)

    return {
        "page": int(page_no),
        "x": float(x),
        "y0": float(y0),
        "y1": float(y1),
        "draw_start_cap": False,
        "draw_end_cap": False,
        "is_stub": False,
        "break_merge_before": bool(start_barrier),
        "break_merge_after": bool(end_barrier),
    }


def build_rail_segments_on_page(page_no, lines, cfg: LayoutConfig):
    if not lines:
        return []

    clusters = cluster_lines_by_indent(lines, x_tol=RAIL_BUCKET_X_TOL)
    out = []
    for col in clusters:
        col_lines = col["lines"]
        if not col_lines:
            continue

        run = [col_lines[0]]
        for ln in col_lines[1:]:
            prev = run[-1]
            gap = float(ln["y0"]) - float(prev["y1"])
            if gap > max(RAIL_JOIN_GAP, cfg.line_gap_break + 8) or _rail_barrier_before(prev, ln):
                seg = _make_rail_segment(page_no, run)
                if seg is not None:
                    out.append(seg)
                run = [ln]
            else:
                run.append(ln)

        seg = _make_rail_segment(page_no, run)
        if seg is not None:
            out.append(seg)

    return out


def detect_segments_rail_stable(doc):
    cfg = LayoutConfig(
        max_indent_from_base=MAX_INDENT_FROM_BASE,
        indent_tol=INDENT_TOL,
        line_gap_break=LINE_GAP_BREAK,
    )

    selected_lines = []
    segments = []
    for page in doc:
        lines = extract_lines(page, cfg, extra_indent=VALIDATION_EXTRA_INDENT)
        page_lines = []
        for ln in lines:
            text = ln["text"].strip()
            if RE_PAGE_NUMBER_ONLY.match(text):
                continue
            page_lines.append(ln)

        selected_lines.extend(page_lines)
        segments.extend(build_rail_segments_on_page(page.number, page_lines, cfg))

    segments = postprocess_segments(segments)
    report = evaluate_segments_vs_expected(doc, cfg, segments)
    return segments, cfg, report, selected_lines


def resolve_auto_model_files(preferred_model=None, extra_model_files=None):
    files = []
    if preferred_model:
        files.append(preferred_model)
    files.extend(DEFAULT_MODEL_FILES)
    if extra_model_files:
        files.extend(extra_model_files)

    seen = set()
    out = []
    for p in files:
        if not p:
            continue
        norm = os.path.normpath(p)
        if norm in seen:
            continue
        seen.add(norm)
        if os.path.exists(norm):
            out.append(norm)
    return out


def detect_segments_auto(doc, preferred_model=None, override_threshold=None, extra_model_files=None):
    cfg = LayoutConfig(
        max_indent_from_base=MAX_INDENT_FROM_BASE,
        indent_tol=INDENT_TOL,
        line_gap_break=LINE_GAP_BREAK,
    )

    candidates = []
    selected_per_model = []

    # Candidate per-model
    model_files = resolve_auto_model_files(
        preferred_model=preferred_model,
        extra_model_files=extra_model_files,
    )
    for model_path in model_files:
        try:
            model = load_local_model(model_path)
        except Exception:
            continue

        best_local = None
        th_candidates = model_threshold_candidates(model, override_threshold=override_threshold)
        for th in th_candidates:
            selected = pick_lines_with_model(doc, model, threshold=th)
            sections, segments, report = build_segments_from_selected_lines(doc, selected, cfg)
            dash_cross = compute_dash_cross_penalty(selected, segments, cfg)
            enum_pen = compute_enum_anchor_penalty(selected, segments)
            short_pen, x_noise, seg_count, stub_count = compute_segment_noise_penalty(segments)
            rank_key = candidate_rank_key(report, dash_cross, enum_pen, short_pen, x_noise, seg_count, stub_count)
            curr = {
                "kind": "local-model",
                "name": f"local:{os.path.basename(model_path)}@{th:.2f}",
                "model_file": model_path,
                "threshold": th,
                "segments": segments,
                "report": report,
                "selected_lines": selected,
                "rank_key": rank_key,
            }
            if best_local is None or curr["rank_key"] > best_local["rank_key"]:
                best_local = curr

        if best_local is not None:
            candidates.append(best_local)
            selected_per_model.append(best_local["selected_lines"])

    # Candidate ensemble voting dari semua model terbaik
    if selected_per_model:
        voted = combine_selected_lines_vote(selected_per_model, min_votes=2)
        if voted:
            _, seg_vote, rep_vote = build_segments_from_selected_lines(doc, voted, cfg)
            dash_cross = compute_dash_cross_penalty(voted, seg_vote, cfg)
            enum_pen = compute_enum_anchor_penalty(voted, seg_vote)
            short_pen, x_noise, seg_count, stub_count = compute_segment_noise_penalty(seg_vote)
            rank_key = candidate_rank_key(rep_vote, dash_cross, enum_pen, short_pen, x_noise, seg_count, stub_count)
            candidates.append(
                {
                    "kind": "ensemble",
                    "name": "ensemble-vote2",
                    "segments": seg_vote,
                    "report": rep_vote,
                    "threshold": None,
                    "rank_key": rank_key,
                }
            )

    # Fallback ke rail-stable jika kandidat model tidak ada.
    if not candidates:
        rail_segments, _, rail_report, rail_selected = detect_segments_rail_stable(doc)
        rail_dash_cross = compute_dash_cross_penalty(rail_selected, rail_segments, cfg)
        rail_enum_pen = compute_enum_anchor_penalty(rail_selected, rail_segments)
        rail_short_pen, rail_x_noise, rail_seg_count, rail_stub_count = compute_segment_noise_penalty(rail_segments)
        rail_rank = candidate_rank_key(
            rail_report,
            rail_dash_cross,
            rail_enum_pen,
            rail_short_pen,
            rail_x_noise,
            rail_seg_count,
            rail_stub_count,
        )
        candidates.append(
            {
                "kind": "rail-stable",
                "name": "rail-left-stable-fallback",
                "segments": rail_segments,
                "report": rail_report,
                "threshold": None,
                "rank_key": rail_rank,
            }
        )

    # Fallback ke rule hanya jika kandidat model/rail tidak ada.
    if not candidates:
        _, sections_rule = build_sections(doc, cfg, extra_indent=0)
        seg_rule = plan_segments(sections_rule, cfg)
        rep_rule = evaluate_segments_vs_expected(doc, cfg, seg_rule)
        short_pen, x_noise, seg_count, stub_count = compute_segment_noise_penalty(seg_rule)
        key_rule = candidate_rank_key(rep_rule, 0, 0, short_pen, x_noise, seg_count, stub_count)
        candidates.append(
            {
                "kind": "rule",
                "name": "rule-fallback",
                "segments": seg_rule,
                "report": rep_rule,
                "threshold": None,
                "rank_key": key_rule,
            }
        )

    if not candidates:
        raise RuntimeError("Tidak ada kandidat deteksi yang berhasil diproses.")

    best = max(candidates, key=lambda c: c["rank_key"])
    return best["segments"], cfg, best["report"], best


def process_pdf_local_model(input_pdf, output_pdf, model_path, threshold=None):
    doc = fitz.open(input_pdf)
    try:
        model = load_local_model(model_path)
        cfg = LayoutConfig(
            max_indent_from_base=MAX_INDENT_FROM_BASE,
            indent_tol=INDENT_TOL,
            line_gap_break=LINE_GAP_BREAK,
        )

        base_th = model.threshold if threshold is None else threshold
        threshold_candidates = sorted(
            set(
                [
                    max(0.05, base_th - 0.1),
                    max(0.05, base_th - 0.05),
                    base_th,
                    min(0.95, base_th + 0.05),
                    min(0.95, base_th + 0.1),
                ]
            )
        )

        best_segments = []
        best_report = None
        best_th = base_th
        best_selected_lines = []
        used_mode = "local-model"
        for th in threshold_candidates:
            selected_lines = pick_lines_with_model(doc, model, threshold=th)
            sections = filter_sections(split_runs(selected_lines, cfg))
            segments = plan_segments(sections, cfg)
            report = evaluate_segments_vs_expected(doc, cfg, segments)

            if best_report is None:
                best_segments, best_report, best_th = segments, report, th
                best_selected_lines = selected_lines
            else:
                best_key = (
                    best_report.passed,
                    best_report.coverage,
                    -best_report.uncovered_count,
                    -best_report.long_uncovered_count,
                )
                curr_key = (
                    report.passed,
                    report.coverage,
                    -report.uncovered_count,
                    -report.long_uncovered_count,
                )
                if curr_key > best_key:
                    best_segments, best_report, best_th = segments, report, th
                    best_selected_lines = selected_lines

            if report.passed:
                break

        if best_report is None:
            raise RuntimeError("Model lokal gagal menghasilkan report.")

        if not best_report.passed:
            sample_text = "; ".join(best_report.samples) if best_report.samples else "-"
            raise RuntimeError(
                "Validasi model lokal belum lolos. "
                f"coverage={best_report.coverage:.2%}, "
                f"uncovered={best_report.uncovered_count}, "
                f"long_uncovered={best_report.long_uncovered_count}. "
                f"Contoh baris belum tergaris: {sample_text}. "
                "Silakan retrain model dengan PDF contoh lebih banyak."
            )

        draw_segments(doc, best_segments)

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

        return final_output, cfg, best_report, best_th, "local-model"
    finally:
        doc.close()


def process_pdf_rail_stable(input_pdf, output_pdf):
    doc = fitz.open(input_pdf)
    try:
        segments, cfg, report, _ = detect_segments_rail_stable(doc)
        if not report.passed:
            sample_text = "; ".join(report.samples) if report.samples else "-"
            raise RuntimeError(
                "Validasi rail-stable belum lolos. "
                f"coverage={report.coverage:.2%}, "
                f"uncovered={report.uncovered_count}, "
                f"long_uncovered={report.long_uncovered_count}. "
                f"Contoh baris belum tergaris: {sample_text}"
            )

        draw_segments(doc, segments)

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

        return final_output, cfg, report
    finally:
        doc.close()


def process_pdf_auto(input_pdf, output_pdf, preferred_model=None, threshold=None, extra_model_files=None):
    doc = fitz.open(input_pdf)
    try:
        segments, cfg, report, chosen = detect_segments_auto(
            doc,
            preferred_model=preferred_model,
            override_threshold=threshold,
            extra_model_files=extra_model_files,
        )
        if not report.passed:
            sample_text = "; ".join(report.samples) if report.samples else "-"
            raise RuntimeError(
                "Validasi auto-ensemble belum lolos. "
                f"coverage={report.coverage:.2%}, "
                f"uncovered={report.uncovered_count}, "
                f"long_uncovered={report.long_uncovered_count}. "
                f"Contoh baris belum tergaris: {sample_text}"
            )

        draw_segments(doc, segments)

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

        return final_output, cfg, report, chosen
    finally:
        doc.close()


def process_pdf(input_pdf, output_pdf):
    doc = fitz.open(input_pdf)
    try:
        cfg, report = pick_best_config(doc)
        if cfg is None or report is None:
            raise RuntimeError("Tidak bisa menentukan konfigurasi garis.")

        if not report.passed:
            sample_text = "; ".join(report.samples) if report.samples else "-"
            raise RuntimeError(
                "Validasi otomatis belum lolos. "
                f"coverage={report.coverage:.2%}, "
                f"uncovered={report.uncovered_count}, "
                f"long_uncovered={report.long_uncovered_count}. "
                f"Contoh baris belum tergaris: {sample_text}"
            )

        _, sections = build_sections(doc, cfg, extra_indent=0)
        segments = plan_segments(sections, cfg)
        draw_segments(doc, segments)

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

        return final_output, cfg, report
    finally:
        doc.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Tambahkan garis kiri pada paragraf akta.")
    parser.add_argument("-i", "--input", default=INPUT_PDF, help="File PDF input")
    parser.add_argument("-o", "--output", default=OUTPUT_PDF, help="File PDF output")
    parser.add_argument(
        "--engine",
        choices=["auto", "local-model", "rail-stable", "rule"],
        default="auto",
        help="Pilih engine deteksi garis: auto (ensemble, default), local-model, rail-stable, atau rule",
    )
    parser.add_argument(
        "--model-file",
        default="garis_local_model_bodyx_v9.json",
        help="Path model lokal (.json) untuk engine local-model",
    )
    parser.add_argument(
        "--threshold",
        type=float,
        default=None,
        help="Override threshold model lokal (0..1). Jika kosong, pakai threshold dari model.",
    )
    parser.add_argument(
        "--extra-model-files",
        nargs="+",
        default=None,
        help="Tambahan path model lokal untuk engine auto.",
    )
    parser.add_argument(
        "--train-local-model",
        action="store_true",
        help="Mode training model lokal dari PDF contoh.",
    )
    parser.add_argument(
        "--train-files",
        nargs="+",
        default=None,
        help="Daftar PDF training untuk model lokal. Jika kosong, pakai --input.",
    )
    parser.add_argument("--epochs", type=int, default=20, help="Jumlah epoch training model lokal.")
    parser.add_argument("--lr", type=float, default=0.08, help="Learning rate training model lokal.")
    parser.add_argument("--seed", type=int, default=42, help="Random seed training model lokal.")
    args = parser.parse_args()

    try:
        if args.train_local_model:
            train_files = args.train_files if args.train_files else [args.input]
            _, meta = train_local_model_from_pdfs(
                train_files,
                model_path=args.model_file,
                epochs=args.epochs,
                lr=args.lr,
                threshold=0.5 if args.threshold is None else args.threshold,
                seed=args.seed,
            )
            print(
                "Training selesai: "
                f"{args.model_file} | "
                f"samples={meta['samples_total']} "
                f"(pos={meta['samples_pos']}, neg={meta['samples_neg']}) | "
                f"acc={meta['train_accuracy_on_samples']:.2%}"
            )
        elif args.engine == "local-model":
            out, cfg_used, report, used_th, used_mode = process_pdf_local_model(
                args.input,
                args.output,
                args.model_file,
                threshold=args.threshold,
            )
            print(
                f"Selesai ({used_mode}): "
                f"{out} | "
                f"coverage={report.coverage:.2%} "
                f"(covered={report.covered_count}/{report.expected_count}) | "
                f"threshold={used_th:.2f} | "
                f"cfg(max_indent={cfg_used.max_indent_from_base}, "
                f"indent_tol={cfg_used.indent_tol}, "
                f"line_gap={cfg_used.line_gap_break})"
            )
        elif args.engine == "rule":
            out, cfg_used, report = process_pdf(args.input, args.output)
            print(
                "Selesai (rule): "
                f"{out} | "
                f"coverage={report.coverage:.2%} "
                f"(covered={report.covered_count}/{report.expected_count}) | "
                f"cfg(max_indent={cfg_used.max_indent_from_base}, "
                f"indent_tol={cfg_used.indent_tol}, "
                f"line_gap={cfg_used.line_gap_break})"
            )
        elif args.engine == "rail-stable":
            out, cfg_used, report = process_pdf_rail_stable(args.input, args.output)
            print(
                "Selesai (rail-stable): "
                f"{out} | "
                f"coverage={report.coverage:.2%} "
                f"(covered={report.covered_count}/{report.expected_count}) | "
                f"cfg(max_indent={cfg_used.max_indent_from_base}, "
                f"indent_tol={cfg_used.indent_tol}, "
                f"line_gap={cfg_used.line_gap_break})"
            )
        else:
            out, cfg_used, report, chosen = process_pdf_auto(
                args.input,
                args.output,
                preferred_model=args.model_file,
                threshold=args.threshold,
                extra_model_files=args.extra_model_files,
            )
            chosen_name = chosen.get("name", chosen.get("kind", "auto"))
            print(
                f"Selesai (auto:{chosen_name}): "
                f"{out} | "
                f"coverage={report.coverage:.2%} "
                f"(covered={report.covered_count}/{report.expected_count}) | "
                f"cfg(max_indent={cfg_used.max_indent_from_base}, "
                f"indent_tol={cfg_used.indent_tol}, "
                f"line_gap={cfg_used.line_gap_break})"
            )
    except RuntimeError as exc:
        print(f"Gagal: {exc}")
        raise SystemExit(1)

