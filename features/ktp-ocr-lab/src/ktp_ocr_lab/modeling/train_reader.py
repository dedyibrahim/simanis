from __future__ import annotations

import argparse
import json
import pickle
from pathlib import Path

from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.feature_extraction import DictVectorizer
from sklearn.metrics import classification_report
from sklearn.pipeline import Pipeline


def main() -> None:
    parser = argparse.ArgumentParser(description="Train candidate scoring model for KTP reader.")
    parser.add_argument("--dataset", default="datasets/reader-candidates.jsonl")
    parser.add_argument("--output", default="models/ktp-reader.pkl")
    args = parser.parse_args()

    rows = [json.loads(line) for line in Path(args.dataset).read_text(encoding="utf-8").splitlines() if line.strip()]
    if not rows:
        raise RuntimeError("Dataset kosong.")

    features = [row["features"] for row in rows]
    labels = [row["label"] for row in rows]

    model = Pipeline(
        [
            ("vectorizer", DictVectorizer(sparse=False)),
            ("classifier", HistGradientBoostingClassifier(max_iter=120, learning_rate=0.08)),
        ]
    )
    model.fit(features, labels)
    predictions = model.predict(features)
    print(classification_report(labels, predictions, zero_division=0))

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("wb") as handle:
        pickle.dump(model, handle)
    print(f"Wrote {output_path}")


if __name__ == "__main__":
    main()
