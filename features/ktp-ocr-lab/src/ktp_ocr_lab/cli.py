from __future__ import annotations

import argparse
from pathlib import Path

from ktp_ocr_lab.models import load_config
from ktp_ocr_lab.pipeline import run_pipeline


def main() -> None:
    parser = argparse.ArgumentParser(description="Run KTP OCR lab pipeline.")
    parser.add_argument("--image", required=True, help="Path gambar KTP.")
    parser.add_argument("--config", default="configs/baseline.json", help="Path config JSON.")
    parser.add_argument("--engine", help="Override OCR engine dari config.")
    parser.add_argument("--output-dir", default="outputs", help="Folder output JSON dan image preprocessing.")
    args = parser.parse_args()

    config_path = Path(args.config)
    image_path = Path(args.image)
    output_dir = Path(args.output_dir)

    config = load_config(config_path)
    if args.engine:
        config.ocr.engine = args.engine

    result = run_pipeline(image_path, config, output_dir)
    output_path = output_dir / f"{image_path.stem}.{config.name}.{config.ocr.engine}.json"
    output_path.write_text(result.model_dump_json(indent=2), encoding="utf-8")

    print(f"Wrote {output_path}")


if __name__ == "__main__":
    main()

