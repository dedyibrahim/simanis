FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    KTP_OCR_ROOT=/app \
    PIP_NO_CACHE_DIR=1 \
    FLAGS_use_onednn=0 \
    FLAGS_enable_pir_api=0

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libglib2.0-0 \
        libgl1 \
        libgomp1 \
        tesseract-ocr \
        tesseract-ocr-eng \
    && rm -rf /var/lib/apt/lists/*

COPY pyproject.toml requirements.txt ./
COPY src ./src
COPY configs ./configs
COPY web ./web
COPY samples ./samples

RUN pip install --upgrade pip \
    && pip install "numpy>=1.24,<2.4" paddlepaddle==3.2.2 paddleocr \
    && pip install .

EXPOSE 8765

CMD ["ktp-ocr-web", "--host", "0.0.0.0", "--port", "8765"]
