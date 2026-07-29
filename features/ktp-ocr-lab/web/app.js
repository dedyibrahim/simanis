const form = document.querySelector("#ocrForm");
const imageInput = document.querySelector("#imageInput");
const configSelect = document.querySelector("#configSelect");
const engineSelect = document.querySelector("#engineSelect");
const sampleButton = document.querySelector("#sampleButton");
const runButton = document.querySelector("#runButton");
const statusBox = document.querySelector("#statusBox");
const previewImage = document.querySelector("#previewImage");
const processedImage = document.querySelector("#processedImage");
const emptyPreview = document.querySelector("#emptyPreview");
const emptyProcessed = document.querySelector("#emptyProcessed");
const fieldsTable = document.querySelector("#fieldsTable");
const rawText = document.querySelector("#rawText");
const jsonText = document.querySelector("#jsonText");
const copyJsonButton = document.querySelector("#copyJsonButton");

const metricSize = document.querySelector("#metricSize");
const metricBlur = document.querySelector("#metricBlur");
const metricStatus = document.querySelector("#metricStatus");
const metricDuration = document.querySelector("#metricDuration");

let sampleImagePath = "";
let latestJson = "";

const fieldLabels = {
  nik: "NIK",
  nama: "Nama",
  tempat_tanggal_lahir: "Tempat/Tgl Lahir",
  jenis_kelamin: "Jenis Kelamin",
  golongan_darah: "Gol. Darah",
  alamat: "Alamat",
  rt_rw: "RT/RW",
  kel_desa: "Kel/Desa",
  kecamatan: "Kecamatan",
  agama: "Agama",
  status_perkawinan: "Status Perkawinan",
  pekerjaan: "Pekerjaan",
  kewarganegaraan: "Kewarganegaraan",
  berlaku_hingga: "Berlaku Hingga",
};

init();

async function init() {
  await loadConfigs();
  await loadEngines();
  bindEvents();
}

async function loadConfigs() {
  const response = await fetch("/api/configs");
  const data = await response.json();
  configSelect.innerHTML = data.configs
    .map((name) => `<option value="${name}">${name}</option>`)
    .join("");
}

async function loadEngines() {
  const response = await fetch("/api/engines");
  const data = await response.json();
  engineSelect.innerHTML = data.engines
    .map((engine) => {
      const disabled = engine.available ? "" : "disabled";
      const suffix = engine.available ? "" : " - belum terinstall";
      return `<option value="${engine.value}" ${disabled}>${engine.label}${suffix}</option>`;
    })
    .join("");

  const paddle = data.engines.find((engine) => engine.value === "paddleocr" && engine.available);
  const tesseract = data.engines.find((engine) => engine.value === "tesseract" && engine.available);
  engineSelect.value = paddle ? "paddleocr" : tesseract ? "tesseract" : "mock";
}

function bindEvents() {
  imageInput.addEventListener("change", () => {
    const file = imageInput.files[0];
    if (!file) return;
    sampleImagePath = "";
    if (engineSelect.value === "mock") {
      engineSelect.value = Array.from(engineSelect.options).some((option) => option.value === "paddleocr" && !option.disabled)
        ? "paddleocr"
        : "tesseract";
    }
    if (configSelect.value === "baseline.json") {
      configSelect.value = engineSelect.value === "paddleocr" ? "paddleocr-fast.json" : "tesseract-fast.json";
    }
    previewImage.src = URL.createObjectURL(file);
    showImage(previewImage, emptyPreview);
    resetProcessed();
    setStatus(`Gambar siap: ${file.name}`);
  });

  sampleButton.addEventListener("click", async () => {
    setStatus("Membuat sample dummy...");
    const response = await fetch("/api/sample", { method: "POST" });
    const data = await response.json();
    sampleImagePath = data.image_path;
    imageInput.value = "";
    engineSelect.value = "mock";
    configSelect.value = "baseline.json";
    previewImage.src = `${data.image_url}?t=${Date.now()}`;
    showImage(previewImage, emptyPreview);
    resetProcessed();
    setStatus("Sample dummy siap diproses.");
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await runOcr();
  });

  document.querySelectorAll(".tab").forEach((tab) => {
    tab.addEventListener("click", () => switchTab(tab.dataset.tab));
  });

  copyJsonButton.addEventListener("click", async () => {
    if (!latestJson) return;
    await navigator.clipboard.writeText(latestJson);
    setStatus("JSON hasil OCR sudah dicopy.");
  });
}

async function runOcr() {
  const formData = new FormData();
  const file = imageInput.files[0];

  if (file) {
    formData.append("image", file);
  } else if (sampleImagePath) {
    formData.append("image_path", sampleImagePath);
  } else {
    setStatus("Pilih gambar atau gunakan sample dulu.");
    return;
  }

  formData.append("config_name", configSelect.value);
  formData.append("engine", engineSelect.value);

  runButton.disabled = true;
  clearResult();
  setStatus("OCR sedang berjalan...");

  try {
    const response = await fetch("/api/ocr", { method: "POST", body: formData });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.detail || "OCR gagal.");
    }
    renderResult(data);
    setStatus(`OCR selesai memakai engine ${data.engine}. Review field sebelum dipakai ke data client.`);
  } catch (error) {
    setStatus(error.message);
  } finally {
    runButton.disabled = false;
  }
}

function clearResult() {
  latestJson = "";
  fieldsTable.innerHTML = "";
  rawText.textContent = "";
  jsonText.textContent = "";
  metricSize.textContent = "-";
  metricBlur.textContent = "-";
  metricStatus.textContent = "-";
  metricDuration.textContent = "-";
  resetProcessed();
}

function renderResult(data) {
  latestJson = JSON.stringify(data, null, 2);
  jsonText.textContent = latestJson;
  rawText.textContent = data.raw_text || "";

  if (data.processed_image_url) {
    processedImage.src = `${data.processed_image_url}?t=${Date.now()}`;
    showImage(processedImage, emptyProcessed);
  }

  const quality = data.quality || {};
  metricSize.textContent = quality.width && quality.height ? `${quality.width} x ${quality.height}` : "-";
  metricBlur.textContent = quality.blur_score ?? "-";
  metricStatus.textContent = quality.likely_blurry ? "Blur" : quality.too_small ? "Kecil" : "Bagus";
  metricDuration.textContent = data.metadata?.duration_ms ? `${data.metadata.duration_ms} ms` : "-";

  fieldsTable.innerHTML = Object.entries(fieldLabels)
    .map(([key, label]) => {
      const field = data.fields?.[key] || {};
      const value = field.value || "-";
      const confidence = field.confidence == null ? "-" : `${Math.round(field.confidence * 100)}%`;
      return `
        <div class="field-row">
          <div class="field-key">${label}</div>
          <div class="field-value">${escapeHtml(value)}</div>
          <div class="confidence">${confidence}</div>
        </div>
      `;
    })
    .join("");
}

function switchTab(name) {
  document.querySelectorAll(".tab").forEach((tab) => {
    tab.classList.toggle("active", tab.dataset.tab === name);
  });
  rawText.classList.toggle("active", name === "raw");
  jsonText.classList.toggle("active", name === "json");
}

function showImage(image, emptyState) {
  image.style.display = "block";
  emptyState.style.display = "none";
}

function resetProcessed() {
  processedImage.removeAttribute("src");
  processedImage.style.display = "none";
  emptyProcessed.style.display = "block";
}

function setStatus(message) {
  statusBox.textContent = message;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
