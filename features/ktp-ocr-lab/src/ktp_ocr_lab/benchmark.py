from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path

from ktp_ocr_lab.models import load_config
from ktp_ocr_lab.pipeline import run_pipeline


def main() -> None:
    parser = argparse.ArgumentParser(description="Run simple benchmark against JSONL ground truth.")
    parser.add_argument("--dataset", required=True, help="JSONL rows with image_path and fields.")
    parser.add_argument("--config", default="configs/baseline.json")
    parser.add_argument("--output", default="benchmarks/latest.csv")
    args = parser.parse_args()

    config = load_config(Path(args.config))
    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    rows = []
    for row in _read_jsonl(Path(args.dataset)):
        result = run_pipeline(Path(row["image_path"]), config, Path("outputs"))
        expected = row.get("fields", {})
        extracted = {key: field.value for key, field in result.fields.items()}
        rows.append(
            {
                "image_path": row["image_path"],
                "config": config.name,
                "engine": config.ocr.engine,
                "field_accuracy": _field_accuracy(expected, extracted),
                "duration_ms": result.metadata["duration_ms"],
                "blur_score": result.quality.blur_score,
            }
        )

    with output_path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=rows[0].keys() if rows else [])
        if rows:
            writer.writeheader()
            writer.writerows(rows)

    print(f"Wrote {output_path}")


def _read_jsonl(path: Path):
    with path.open(encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if line:
                yield json.loads(line)


def _field_accuracy(expected: dict[str, str], extracted: dict[str, str | None]) -> float:
    if not expected:
        return 0.0
    correct = sum(1 for key, value in expected.items() if extracted.get(key) == value)
    return round(correct / len(expected), 4)


if __name__ == "__main__":
    main()

