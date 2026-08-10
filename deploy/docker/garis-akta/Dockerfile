FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    GARIS_AKTA_ROOT=/app

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libreoffice-writer \
        libreoffice-core \
        fonts-dejavu \
        fontconfig \
        libglib2.0-0 \
        libgl1 \
        libgomp1 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --upgrade pip \
    && pip install -r requirements.txt

COPY app.py garis_akta.py scan_line_apply.py scan_line_model_datatrain_v2.json ./

EXPOSE 8788

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8788"]
