from __future__ import annotations

import re

from ktp_ocr_lab.models import OcrLine


FIELD_LABEL_HINTS = {
    "nik": ["NIK"],
    "nama": ["NAMA"],
    "tempat_tanggal_lahir": ["TEMPAT", "LAHIR", "TGL"],
    "jenis_kelamin": ["JENIS", "KELAMIN"],
    "golongan_darah": ["GOL", "DARAH"],
    "alamat": ["ALAMAT"],
    "rt_rw": ["RT", "RW"],
    "kel_desa": ["KEL", "DESA"],
    "kecamatan": ["KECAMATAN"],
    "agama": ["AGAMA"],
    "status_perkawinan": ["STATUS", "PERKAWINAN"],
    "pekerjaan": ["PEKERJAAN"],
    "kewarganegaraan": ["KEWARGANEGARAAN"],
    "berlaku_hingga": ["BERLAKU", "HINGGA"],
}


def generate_candidates(lines: list[OcrLine], fields: list[str]) -> list[dict[str, str]]:
    candidates: list[dict[str, str]] = []
    normalized_lines = [line.text.strip() for line in lines if line.text.strip()]

    for index, text in enumerate(normalized_lines):
        cleaned = _clean_candidate(text)
        if not cleaned:
            continue

        for field in fields:
            candidates.extend(_line_candidates(field, cleaned, text))

        if index + 1 < len(normalized_lines):
            pair = _clean_candidate(f"{text} {normalized_lines[index + 1]}")
            for field in fields:
                candidates.extend(_line_candidates(field, pair, text))

    return _dedupe(candidates)


def candidate_features(field: str, candidate: str, source_line: str) -> dict[str, float | int | str]:
    upper = candidate.upper()
    source = source_line.upper()
    digits = re.sub(r"\D", "", upper)
    alpha = re.sub(r"[^A-Z]", "", upper)
    hints = FIELD_LABEL_HINTS.get(field, [])

    return {
        "field": field,
        "length": len(candidate),
        "digit_count": len(digits),
        "alpha_count": len(alpha),
        "word_count": len(candidate.split()),
        "has_date": int(bool(re.search(r"\d{1,2}[-/]\d{1,2}[-/]\d{2,4}", upper))),
        "has_slash": int("/" in upper),
        "has_comma": int("," in upper),
        "has_colon": int(":" in source or "=" in source),
        "label_hint_count": sum(int(hint in source) for hint in hints),
        "is_16_digits": int(field == "nik" and len(digits) == 16),
        "starts_with_known_gender": int(upper.startswith("LAKI") or upper.startswith("PEREMPUAN")),
        "contains_known_religion": int(any(token in upper for token in ["ISLAM", "KRISTEN", "KATOLIK", "HINDU", "BUDDHA", "KONGHUCU"])),
        "contains_wn": int("WNI" in upper or "WNA" in upper),
        "candidate": candidate,
    }


def _line_candidates(field: str, cleaned: str, source_line: str) -> list[dict[str, str]]:
    values = [cleaned]
    if ":" in cleaned:
        values.append(cleaned.split(":", 1)[1].strip())
    if "=" in cleaned:
        values.append(cleaned.split("=", 1)[1].strip())

    candidates = []
    for value in values:
        value = _clean_candidate(value)
        if not value:
            continue
        if field == "nik":
            digits = re.sub(r"\D", "", value.translate(str.maketrans({"O": "0", "I": "1", "L": "1", "B": "6", "E": "2"})))
            if len(digits) >= 14:
                value = digits
            else:
                continue
        candidates.append({"field": field, "candidate": value, "source_line": source_line})
    return candidates


def _clean_candidate(value: str) -> str:
    value = value.replace("�", " ")
    value = re.sub(r"\s+", " ", value)
    return value.strip(" :.-|;,\"'")


def _dedupe(candidates: list[dict[str, str]]) -> list[dict[str, str]]:
    seen = set()
    unique = []
    for candidate in candidates:
        key = (candidate["field"], candidate["candidate"].upper())
        if key in seen:
            continue
        seen.add(key)
        unique.append(candidate)
    return unique

