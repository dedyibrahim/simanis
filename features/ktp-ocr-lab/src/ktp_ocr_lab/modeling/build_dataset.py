from __future__ import annotations

import argparse
import json
from pathlib import Path

from ktp_ocr_lab.modeling.features import candidate_features, generate_candidates
from ktp_ocr_lab.modeling.schema import CandidateRecord, GroundTruthRecord
from ktp_ocr_lab.models import load_config
from ktp_ocr_lab.ocr_engines.base import create_engine
from ktp_ocr_lab.preprocessing.image_ops import load_image, preprocess


def main() -> None:
    parser = argparse.ArgumentParser(description="Build KTP reader candidate dataset from labeled JSONL.")
    parser.add_argument("--ground-truth", required=True, help="JSONL with image_path and fields.")
    parser.add_argument("--config", default="configs/tesseract-fast.json")
    parser.add_argument("--output", default="datasets/reader-candidates.jsonl")
    args = parser.parse_args()

    config = load_config(Path(args.config))
    engine = create_engine(config.ocr)
    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    rows: list[CandidateRecord] = []
    for record in _read_ground_truth(Path(args.ground_truth)):
        image = load_image(record.image_path)
        processed = preprocess(image, config.preprocessing)
        lines = engine.read(processed)
        candidates = generate_candidates(lines, list(record.fields.keys()))
        for candidate in candidates:
            expected = _normalize(record.fields.get(candidate["field"], ""))
            proposed = _normalize(candidate["candidate"])
            rows.append(
                CandidateRecord(
                    image_path=record.image_path,
                    field=candidate["field"],
                    candidate=candidate["candidate"],
                    source_line=candidate["source_line"],
                    label=int(bool(expected and proposed == expected)),
                    features=candidate_features(candidate["field"], candidate["candidate"], candidate["source_line"]),
                )
            )

    with output_path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(row.model_dump_json() + "\n")

    print(f"Wrote {len(rows)} candidates to {output_path}")


def _read_ground_truth(path: Path):
    with path.open(encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if line:
                yield GroundTruthRecord.model_validate_json(line)


def _normalize(value: str) -> str:
    return " ".join(value.upper().split())


if __name__ == "__main__":
    main()

