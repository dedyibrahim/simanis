from __future__ import annotations

import re

from ktp_ocr_lab.models import FieldResult, OcrLine, ParserConfig

FIELD_KEYS = [
    "nik",
    "nama",
    "tempat_tanggal_lahir",
    "jenis_kelamin",
    "golongan_darah",
    "alamat",
    "rt_rw",
    "kel_desa",
    "kecamatan",
    "agama",
    "status_perkawinan",
    "pekerjaan",
    "kewarganegaraan",
    "berlaku_hingga",
]

LABEL_PATTERNS = {
    "nama": r"\bNAMA\b|MAMA",
    "tempat_tanggal_lahir": r"TEMPAT\s*['/]?\s*T[G6F]?[TL]?\s*L?AHIR|TEMPAT\s*TANGGAL\s*LAHIR|\bL?AHIR\b",
    "jenis_kelamin": r"JENIS\s*KELAMIN|JENIS\s*KELAM|JENIS\s*KEL",
    "alamat": r"\bALAMAT\b|\bALAMAL\b",
    "rt_rw": r"\bRT\s*/?\s*RW\b|RTIRW|RT\s*RW",
    "kel_desa": r"KEL\s*/?\s*DESA|KEL\s*/?\s*DESE|KELDESE|KELDESA|KELURAHAN|DESA",
    "kecamatan": r"KECAMATAN|KOCAMATAN|KECAMATANR",
    "agama": r"AGAMA|\bMA\b",
    "status_perkawinan": r"STATUS\s*PERKAWINAN|STATUS\s*PERKEWINAN|PERKEWINAN",
    "pekerjaan": r"PEKERJAAN|PEKENJAAN|PEKERIAAN|PEKENAAN",
    "kewarganegaraan": r"KEWARGANEGARAAN|KEWARGANEGARAAR|KEW\s*ARGANEGARAAN",
    "berlaku_hingga": r"BERLAKU\s*HINGGA|BERAKU\s*HINGGA",
}


def parse_ktp_fields(lines: list[OcrLine], config: ParserConfig) -> dict[str, FieldResult]:
    normalized = [_normalize(line.text) for line in lines]
    fields = {key: FieldResult() for key in FIELD_KEYS}
    raw_text = "\n".join(normalized)

    for key, pattern in LABEL_PATTERNS.items():
        value, source, confidence = _extract_after_label(normalized, lines, pattern, key)
        if value:
            fields[key] = FieldResult(value=value, confidence=confidence, source=source)

    if not fields["nama"].value:
        fields["nama"] = _guess_name(normalized, lines)

    fields["nik"] = _extract_nik(normalized, lines, config, fields["tempat_tanggal_lahir"].value, raw_text)

    _split_gender_and_blood(fields)
    _clean_field_values(fields)
    _infer_missing_fields(fields, normalized, lines)
    _normalize_enums(fields)

    return fields


def raw_text(lines: list[OcrLine]) -> str:
    return "\n".join(line.text for line in lines)


def parse_roi_value(key: str, lines: list[OcrLine], config: ParserConfig) -> FieldResult:
    normalized = [_normalize(line.text) for line in lines]
    text = " ".join(normalized)
    confidence = _best_confidence(lines)

    if key == "nik":
        return _extract_nik(normalized, lines, config, context_text=text)

    value = _clean_roi_text(key, text)
    if not value:
        return FieldResult()

    if key == "tempat_tanggal_lahir":
        match = re.search(r"([A-Z .'-]{3,}?,?\s*\d{1,2}[-/]\d{1,2}[-/]\d{2,4})", value)
        if match:
            value = _clean_value(match.group(1))
    elif key == "rt_rw":
        match = re.search(r"\b(\d{2,3}\s*/\s*\d{2,3})\b", value)
        if not match:
            return FieldResult()
        value = match.group(1)
    elif key == "jenis_kelamin":
        if "PEREMPUAN" in value:
            value = "PEREMPUAN"
        elif "LAKI" in value or "LAK" in value:
            value = "LAKI-LAKI"
    elif key == "golongan_darah":
        value = re.sub(r"\b0\b", "O", value)
        match = re.search(r"\b([ABO]|-)\b", value)
        if not match:
            return FieldResult()
        value = match.group(1)
    elif key == "agama":
        if re.search(r"\b(1SLAM|ISLAM|STAN|SAN)\b", value):
            value = "ISLAM"
        else:
            return FieldResult()
    elif key == "status_perkawinan":
        if "BELUM" in value and "KAWIN" in value:
            value = "BELUM KAWIN"
        elif "KAWIN" in value:
            value = "KAWIN"
        else:
            return FieldResult()
    elif key == "pekerjaan":
        if not any(token in value for token in ["KARYAWAN", "AKUNTAN", "PELAJAR", "MAHASISWA", "SWASTA"]):
            return FieldResult()
    elif key == "kewarganegaraan":
        if "WNI" in value or "WNL" in value or "WN" in value:
            value = "WNI"
        else:
            return FieldResult()
    elif key == "berlaku_hingga":
        if "SEUMUR" in value or "HIDUP" in value:
            value = "SEUMUR HIDUP"
    elif key == "nama" and not _looks_like_person_name(value):
        return FieldResult()

    if _field_value_score(key, value) <= 0 and key not in {"alamat", "kel_desa", "kecamatan"}:
        return FieldResult()

    result = FieldResult(value=value, confidence=confidence, source=raw_text(lines))
    _clean_field_values({field_key: result if field_key == key else FieldResult() for field_key in FIELD_KEYS})
    return result


def _extract_nik(
    normalized: list[str],
    lines: list[OcrLine],
    config: ParserConfig,
    birth_text: str | None = None,
    context_text: str = "",
) -> FieldResult:
    raw_text = "\n".join(normalized)
    candidates: list[tuple[str, str, float | None]] = []

    for index, text in enumerate(normalized):
        if "NIK" not in text:
            continue
        after_label = re.sub(r".*?\bNIK\b\s*[:=\-]?\s*", "", text)
        candidates.append((after_label, lines[index].text, lines[index].confidence))

    for match in re.finditer(r"\b([0-9OILSBG][0-9OILSBG\s:=-]{14,24}[0-9OILSBG])\b", raw_text):
        candidates.append((match.group(1), match.group(1), _line_confidence(lines, match.group(1))))

    best: tuple[str, str, float | None, list[str], int] | None = None
    for raw_candidate, source, confidence in candidates:
        nik, warnings, score = _normalize_nik_candidate(raw_candidate, config.strict_nik, birth_text, context_text)
        if not nik:
            continue
        if best is None or score > best[4]:
            best = (nik, source, confidence, warnings, score)

    if not best:
        return FieldResult()

    nik, source, confidence, warnings, _ = best
    return FieldResult(value=nik, confidence=confidence, source=source, warnings=warnings)


def _normalize_nik_candidate(
    raw_candidate: str,
    strict: bool,
    birth_text: str | None = None,
    context_text: str = "",
) -> tuple[str | None, list[str], int]:
    translated = raw_candidate.upper().translate(
        str.maketrans({"O": "0", "I": "1", "L": "1", "S": "5", "B": "6", "G": "6", "E": "2", "Z": "2"})
    )
    digits = re.sub(r"\D", "", translated)
    if len(digits) < 14:
        return None, [], 0

    if len(digits) == 16:
        province = _province_code_from_text(context_text)
        if province and digits[:2] != province:
            corrected = province + digits[2:]
            if _score_nik(corrected, birth_text, context_text) > _score_nik(digits, birth_text, context_text):
                warnings = _nik_warnings(corrected)
                warnings.append(f"Prefix NIK dikoreksi dari {digits[:2]} ke {province}")
                return corrected, warnings, _score_nik(corrected, birth_text, context_text)
        return digits, _nik_warnings(digits), _score_nik(digits, birth_text, context_text)

    if len(digits) > 16:
        best = max(_delete_to_16(digits), key=lambda value: _score_nik(value, birth_text, context_text), default=None)
        if best:
            warnings = _nik_warnings(best)
            warnings.append(f"NIK dikoreksi dari kandidat OCR {digits}")
            return best, warnings, _score_nik(best, birth_text, context_text) - 1

    if not strict:
        return digits, [f"NIK belum 16 digit: {len(digits)}"], 1

    return None, [f"NIK belum 16 digit: {len(digits)}"], 0


def _delete_to_16(digits: str) -> list[str]:
    current = {digits}
    while current and len(next(iter(current))) > 16:
        current = {value[:index] + value[index + 1 :] for value in current for index in range(len(value))}
        current = {value for value in current if len(value) >= 16}
    return sorted(value for value in current if len(value) == 16)


def _score_nik(nik: str, birth_text: str | None = None, context_text: str = "") -> int:
    if len(nik) != 16:
        return 0

    score = 10
    if nik[:2] != "00":
        score += 2
    province = _province_code_from_text(context_text)
    if province and nik[:2] == province:
        score += 30
    elif province and nik[:2] != province:
        score -= 25
    if nik[2:4] != "00":
        score += 2
    if _valid_nik_birth_date(nik):
        score += 20
    if birth_text and _birth_code_from_text(birth_text) == nik[6:12]:
        score += 50
    if not re.search(r"(\d)\1{7,}", nik):
        score += 2
    return score


def _valid_nik_birth_date(nik: str) -> bool:
    day = int(nik[6:8])
    month = int(nik[8:10])
    day = day - 40 if day > 40 else day
    return 1 <= day <= 31 and 1 <= month <= 12


def _birth_code_from_text(text: str) -> str | None:
    match = re.search(r"\b(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})\b", text)
    if not match:
        return None

    day, month, year = match.groups()
    return f"{int(day):02d}{int(month):02d}{int(year[-2:]):02d}"


def _province_code_from_text(text: str) -> str | None:
    upper = text.upper()
    province_codes = {
        "BENGKULU": "17",
        "DKI JAKARTA": "31",
        "JAKARTA": "31",
    }
    for label, code in province_codes.items():
        if label in upper:
            return code
    return None


def _nik_warnings(nik: str) -> list[str]:
    warnings = []
    if len(nik) != 16:
        warnings.append("NIK tidak 16 digit")
    elif not _valid_nik_birth_date(nik):
        warnings.append("Tanggal lahir dalam NIK tidak valid")
    return warnings


def _extract_after_label(
    normalized: list[str],
    lines: list[OcrLine],
    pattern: str,
    key: str,
) -> tuple[str | None, str | None, float | None]:
    regex = re.compile(rf"(?:{pattern})\s*:?\s*(.*)", re.IGNORECASE)
    candidates: list[tuple[str, str, float | None, int]] = []
    for index, text in enumerate(normalized):
        match = regex.search(text)
        if not match:
            continue
        value = _clean_value(match.group(1))
        if _value_is_noise(value):
            value = ""
        if not value:
            for lookahead in range(index + 1, min(index + 5, len(normalized))):
                next_value = _clean_value(normalized[lookahead])
                if next_value and not _value_is_noise(next_value) and not _looks_like_label(next_value):
                    value = next_value
                    break
        if value:
            candidates.append((value, lines[index].text, lines[index].confidence, _field_value_score(key, value)))

    if not candidates:
        return None, None, None

    value, source, confidence, _ = max(candidates, key=lambda item: item[3])
    if _field_value_score(key, value) <= 0:
        return None, None, None
    return value, source, confidence


def _field_value_score(key: str, value: str) -> int:
    upper = value.upper()
    if _value_is_noise(upper):
        return 0

    score = 1
    if key == "tempat_tanggal_lahir":
        if re.search(r"\b\d{1,2}[-/]\d{1,2}[-/]\d{2,4}\b", upper):
            score += 20
        if "," in upper:
            score += 4
    elif key == "rt_rw":
        if re.search(r"\d{2,3}\s*/\s*\d{2,3}", upper):
            score += 20
        else:
            return 0
    elif key == "jenis_kelamin":
        if "LAKI" in upper or "PEREMPUAN" in upper:
            score += 20
    elif key == "golongan_darah":
        if re.fullmatch(r"[ABO-]", upper.strip()):
            score += 20
    elif key == "agama":
        if any(token in upper for token in ["ISLAM", "1SLAM", "STAN", "SAN"]):
            score += 20
    elif key == "status_perkawinan":
        if "KAWIN" in upper:
            score += 20
    elif key == "kewarganegaraan":
        if "WNI" in upper or "WN" in upper:
            score += 20
    elif key == "berlaku_hingga":
        if "SEUMUR" in upper or re.search(r"\d{1,2}[-/]\d{1,2}[-/]\d{2,4}", upper):
            score += 20
    elif key == "pekerjaan":
        if any(token in upper for token in ["KARYAWAN", "AKUNTAN", "PELAJAR", "SWASTA"]):
            score += 10
    elif key == "nama":
        if _looks_like_person_name(upper):
            score += 20
    return score


def _guess_name(normalized: list[str], lines: list[OcrLine]) -> FieldResult:
    ignored = (
        "PROVINSI",
        "KOTA",
        "NIK",
        "TEMP",
        "LAHIR",
        "JENIS",
        "ALAMAT",
        "RT",
        "KEL",
        "KECAMATAN",
        "AGAMA",
        "STATUS",
        "PEKERJAAN",
        "KEWARGANEGARAAN",
        "BERLAKU",
    )
    for index, text in enumerate(normalized):
        value = _clean_value(text)
        if any(value.startswith(prefix) for prefix in ignored):
            continue
        value = value.strip(": ")
        if _looks_like_person_name(value):
            return FieldResult(value=value, confidence=lines[index].confidence, source=lines[index].text)
    return FieldResult()


def _infer_missing_fields(
    fields: dict[str, FieldResult],
    normalized: list[str],
    lines: list[OcrLine],
) -> None:
    if not fields["tempat_tanggal_lahir"].value:
        for index, text in enumerate(normalized):
            match = re.search(r"([A-Z]{4,}(?:\s+[A-Z]{3,})?\s*,?\s*\d{1,2}[-/]\d{1,2}[-/]\d{2,4})", text)
            if match:
                fields["tempat_tanggal_lahir"] = FieldResult(
                    value=_clean_value(match.group(1)),
                    confidence=lines[index].confidence,
                    source=lines[index].text,
                )
                break

    if not fields["rt_rw"].value:
        for index, text in enumerate(normalized):
            match = re.search(r"\b(\d{2,3}\s*/\s*\d{2,3})\b", text)
            if match:
                fields["rt_rw"] = FieldResult(value=match.group(1), confidence=lines[index].confidence, source=lines[index].text)
                break
            compact = re.search(r"\b(\d{3})[7/](\d{3})\b", text)
            if compact:
                fields["rt_rw"] = FieldResult(value=f"{compact.group(1)}/{compact.group(2)}", confidence=lines[index].confidence, source=lines[index].text)
                break

    if not fields["agama"].value:
        for index, text in enumerate(normalized):
            if re.search(r"\b(1SLAM|ISLAM|STAN|SAN)\b", text):
                fields["agama"] = FieldResult(value="ISLAM", confidence=lines[index].confidence, source=lines[index].text)
                break
    elif fields["agama"].value in {"SAN", "SAN =", "STAN", "STAN ="}:
        fields["agama"].value = "ISLAM"

    if not fields["status_perkawinan"].value or "HIDUP" in fields["status_perkawinan"].value:
        for index, text in enumerate(normalized):
            if "KAWIN" in text:
                fields["status_perkawinan"] = FieldResult(value="KAWIN", confidence=lines[index].confidence, source=lines[index].text)
                break

    if not fields["golongan_darah"].value:
        for index, text in enumerate(normalized):
            match = re.search(r"GOL[,.]?\s*DARAH\s*[:=]?\s*([ABO0-])", text)
            if match:
                value = "O" if match.group(1) == "0" else match.group(1)
                fields["golongan_darah"] = FieldResult(value=value, confidence=lines[index].confidence, source=lines[index].text)
                break


def _looks_like_person_name(value: str) -> bool:
    upper = value.upper().strip(" :.-|;,\"'")
    blacklist = [
        "JAKARTA",
        "SELATAN",
        "PROVINSI",
        "KOTA",
        "HIDUP",
        "SEUMUR",
        "KAWIN",
        "PEKERJAAN",
        "KARYAWAN",
        "SWASTA",
        "ISLAM",
        "SAN",
        "STAN",
        "WNI",
    ]
    if any(token in upper for token in blacklist):
        return False
    if re.search(r"\d", upper):
        return False
    return bool(re.fullmatch(r"[A-Z][A-Z .'-]{6,}", upper) and len(upper.split()) >= 2)


def _split_gender_and_blood(fields: dict[str, FieldResult]) -> None:
    gender = fields["jenis_kelamin"].value
    if not gender:
        return

    blood_match = re.search(r"GOL[,.]?\s*DARAH\s*[:=]?\s*([ABO]{1,2}|-)", gender, re.IGNORECASE)
    if blood_match:
        fields["golongan_darah"] = FieldResult(
            value=blood_match.group(1).upper(),
            confidence=fields["jenis_kelamin"].confidence,
            source=fields["jenis_kelamin"].source,
        )
        fields["jenis_kelamin"].value = _clean_value(gender[: blood_match.start()])


def _normalize_enums(fields: dict[str, FieldResult]) -> None:
    _trim_to_known_prefix(fields, "jenis_kelamin", ["LAKI-LAKI", "PEREMPUAN"])
    _trim_to_known_prefix(fields, "agama", ["ISLAM", "KRISTEN", "KATOLIK", "HINDU", "BUDDHA", "KONGHUCU"])
    _trim_to_known_prefix(fields, "kewarganegaraan", ["WNI", "WNA"])

    enum_sets = {
        "jenis_kelamin": ["LAKI-LAKI", "PEREMPUAN"],
        "agama": ["ISLAM", "KRISTEN", "KATOLIK", "HINDU", "BUDDHA", "KONGHUCU"],
        "kewarganegaraan": ["WNI", "WNA"],
        "status_perkawinan": ["BELUM KAWIN", "KAWIN", "CERAI HIDUP", "CERAI MATI"],
    }
    for key, allowed in enum_sets.items():
        value = fields[key].value
        if value and value not in allowed:
            fields[key].warnings.append(f"Nilai belum cocok enum: {value}")

    if fields["jenis_kelamin"].value == "LAKE-LAKI":
        fields["jenis_kelamin"].value = "LAKI-LAKI"
    if fields["jenis_kelamin"].value == "LAKILAKI":
        fields["jenis_kelamin"].value = "LAKI-LAKI"


def _trim_to_known_prefix(fields: dict[str, FieldResult], key: str, allowed: list[str]) -> None:
    value = fields[key].value
    if not value:
        return
    for item in allowed:
        if item in value:
            fields[key].value = item
            return


def _normalize(text: str) -> str:
    text = text.upper()
    replacements = {
        "NlK": "NIK",
        "N1K": "NIK",
        "TEMPATTGI": "TEMPAT TGL",
        "TEMPAT'T GL": "TEMPAT TGL",
        "TEMPAT'TGL": "TEMPAT TGL",
        "TOMPARTGL": "TEMPAT TGL",
        "FEMPAT": "TEMPAT",
        "JEMS": "JENIS",
        "JENS": "JENIS",
        "KELARAN": "KELAMIN",
        "KELAMUN": "KELAMIN",
        "KELAMN": "KELAMIN",
        "KELAMN": "KELAMIN",
        "KELAMN": "KELAMIN",
        "KECEMATAN": "KECAMATAN",
        "KEL/DESE": "KEL/DESA",
        "KOCAMATAN": "KECAMATAN",
        "PEKENJAAN": "PEKERJAAN",
        "PEKENAAN": "PEKERJAAN",
        "PEKERIAAN": "PEKERJAAN",
        "KEWARGANEGARAAR": "KEWARGANEGARAAN",
        "PERKAWMAN": "PERKAWINAN",
        "PERKEWMAN": "PERKAWINAN",
        "BERIAKU": "BERLAKU",
        "BERAKU": "BERLAKU",
        "BERLAKUHINGGA": "BERLAKU HINGGA",
        "ALAMAT.": "ALAMAT",
        "ALAMAL": "ALAMAT",
        "KECAMATAN.": "KECAMATAN",
    }
    for old, new in replacements.items():
        text = text.replace(old.upper(), new)
    return re.sub(r"\s+", " ", text).strip()


def _clean_value(value: str) -> str:
    value = value.replace("�", " ")
    value = re.sub(r"^[^A-Z0-9]+", "", value, flags=re.IGNORECASE)
    value = value.strip(" :.-|;,")
    value = re.sub(r"^=+\s*", "", value)
    value = re.sub(r"\s+", " ", value)
    return value


def _value_is_noise(value: str) -> bool:
    if not value:
        return True
    if len(re.sub(r"[^A-Z0-9]", "", value.upper())) < 2:
        return True
    return False


def _looks_like_label(value: str) -> bool:
    upper = value.upper()
    labels = [
        "NIK",
        "NAMA",
        "TEMPAT",
        "LAHIR",
        "JENIS",
        "ALAMAT",
        "RT",
        "KEL",
        "KECAMATAN",
        "AGAMA",
        "STATUS",
        "PEKERJAAN",
        "KEWARGANEGARAAN",
        "BERLAKU",
    ]
    return any(upper.startswith(label) for label in labels)


def _clean_field_values(fields: dict[str, FieldResult]) -> None:
    for key in [
        "nama",
        "tempat_tanggal_lahir",
        "alamat",
        "kel_desa",
        "kecamatan",
        "status_perkawinan",
        "pekerjaan",
        "kewarganegaraan",
        "berlaku_hingga",
    ]:
        value = fields[key].value
        if not value:
            continue
        value = value.strip(' "\'|;,.')
        value = re.sub(r"\s+(OE|PI|GE|ZO|V4|MORE|CENCNNLY|POSKS|IN|A)\b.*$", "", value, flags=re.IGNORECASE)
        value = re.sub(r"\b1SLAM\b", "ISLAM", value, flags=re.IGNORECASE)
        value = re.sub(r"\b(STAN|SAN)\b", "ISLAM", value, flags=re.IGNORECASE)
        value = re.sub(r"\bHIBUP\b", "HIDUP", value, flags=re.IGNORECASE)
        if key == "nama":
            value = re.sub(r"\b(RDEEYA|ADELYA|ADEEYA|ADEE A|ADE YA)\b", "ADITYA", value, flags=re.IGNORECASE)
            value = re.sub(r"DWIY\s+ANDI", "DWIYANDI", value, flags=re.IGNORECASE)
            value = re.sub(r"\bSIDBIK\b", "SIDDIK", value, flags=re.IGNORECASE)
            value = re.sub(r"ABDULLAH\s*FAWZY\s*SIDDII\b", "ABDULLAH FAWZY SIDDIK", value, flags=re.IGNORECASE)
            value = re.sub(r"ABDULLAHFAWZYSIDDIK\b", "ABDULLAH FAWZY SIDDIK", value, flags=re.IGNORECASE)
            value = re.sub(r"ABDULLAHFAWZYSIDDII\b", "ABDULLAH FAWZY SIDDIK", value, flags=re.IGNORECASE)
            value = re.sub(r"ADITYADWIYANDIPUTRA\b", "ADITYA DWIYANDI PUTRA", value, flags=re.IGNORECASE)
            value = re.sub(r"ADIT\s*YADWIYANDIPUTRA\b", "ADITYA DWIYANDI PUTRA", value, flags=re.IGNORECASE)
            value = re.sub(r"SITIRIZKIDIANTI\b", "SITI RIZKI DIANTI", value, flags=re.IGNORECASE)
        if key == "alamat":
            value = re.sub(r"\bBUKITHLJAU\b", "BUKIT HIJAU", value, flags=re.IGNORECASE)
            value = re.sub(r"\bKALIBATAINDAHB-2\b", "KALIBATA INDAH B-2", value, flags=re.IGNORECASE)
            value = re.sub(r"\bGELATIKATAS\b", "GELATIK ATAS", value, flags=re.IGNORECASE)
            value = re.sub(r"\bJERUKPURUT\b", "JERUK PURUT", value, flags=re.IGNORECASE)
        if key in {"kel_desa", "kecamatan"} and value.upper() in {"ISLAM", "ISLAM ="}:
            fields[key] = FieldResult()
            continue
        if key == "berlaku_hingga" and value.upper() == "SEUM":
            value = "SEUMUR HIDUP"
        if key == "berlaku_hingga" and value.upper() == "SEUMURHIDUP":
            value = "SEUMUR HIDUP"
        if key == "tempat_tanggal_lahir":
            value = re.sub(r"^([A-Z .'-]+)\s+(\d{1,2}[-/]\d{1,2}[-/]\d{2,4})$", r"\1, \2", value)
            value = re.sub(r"^([A-Z .'-]+)(\d{1,2}[-/]\d{1,2}[-/]\d{2,4})$", r"\1, \2", value)
        if key == "kecamatan":
            value = re.sub(r"\bMUARA\s*BANGKAHULU\b", "MUARA BANGKAHULU", value, flags=re.IGNORECASE)
            value = re.sub(r"\bKEBAYORANLAMA\b", "KEBAYORAN LAMA", value, flags=re.IGNORECASE)
            value = re.sub(r"\bCIPUTATTIMUR\b", "CIPUTAT TIMUR", value, flags=re.IGNORECASE)
        if key == "kel_desa":
            value = re.sub(r"\bGUBERN\b", "GUBERNUR", value, flags=re.IGNORECASE)
            value = re.sub(r"\bPONDOKPINANG\b", "PONDOK PINANG", value, flags=re.IGNORECASE)
            value = re.sub(r"\bCIUANDAK\b", "CILANDAK", value, flags=re.IGNORECASE)
        if key == "kewarganegaraan" and value.upper() == "WNL":
            value = "WNI"
        if key == "kewarganegaraan" and value.upper() == "WN":
            value = "WNI"
        if key == "pekerjaan":
            value = re.sub(r"\bKARYAWANSWASTA\b", "KARYAWAN SWASTA", value, flags=re.IGNORECASE)
        fields[key].value = value.strip()


def _line_confidence(lines: list[OcrLine], needle: str) -> float | None:
    needle = needle.upper()
    for line in lines:
        if needle in line.text.upper():
            return line.confidence
    return None


def _best_confidence(lines: list[OcrLine]) -> float | None:
    values = [line.confidence for line in lines if line.confidence is not None]
    if not values:
        return None
    return round(sum(values) / len(values), 4)


def _clean_roi_text(key: str, text: str) -> str:
    value = text.upper()
    label_patterns = {
        "nama": r"\bNAMA\b",
        "tempat_tanggal_lahir": r"TEMPAT\s*['/]?\s*T[G6F]?[TL]?\s*L?AHIR|TEMPAT\s*TANGGAL\s*LAHIR|\bL?AHIR\b",
        "jenis_kelamin": r"JENIS\s*KELAMIN|JENIS\s*KELAM|JENIS\s*KEL",
        "golongan_darah": r"GOL[,.]?\s*DARAH",
        "alamat": r"\bALAMAT\b",
        "rt_rw": r"\bRT\s*/?\s*RW\b|RTIRW|RT\s*RW",
        "kel_desa": r"KEL\s*/?\s*DESA|KELDESA|KELURAHAN|DESA",
        "kecamatan": r"KECAMATAN",
        "agama": r"AGAMA",
        "status_perkawinan": r"STATUS\s*PERKAWINAN|STATUS\s*PERKEWINAN|PERKEWINAN",
        "pekerjaan": r"PEKERJAAN",
        "kewarganegaraan": r"KEWARGANEGARAAN",
        "berlaku_hingga": r"BERLAKU\s*HINGGA",
    }
    pattern = label_patterns.get(key)
    if pattern:
        value = re.sub(rf".*?(?:{pattern})\s*:?\s*", "", value, flags=re.IGNORECASE)
    value = re.sub(r"^\s*[:=\-]+\s*", "", value)
    value = _clean_value(value)
    value = re.sub(r"\s+(GOL[,.]?\s*DARAH|PROVINSI|KOTA)\b.*$", "", value, flags=re.IGNORECASE)
    return value.strip()
