const { NlpManager } = require('node-nlp');
const express = require('express');
const bodyParser = require('body-parser');
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const FormData = require('form-data');

const app = express();
const port = Number(process.env.PORT || 8020);

const readEnvValueFromFile = (filePath, key) => {
    try {
        if (!filePath || !key || !fs.existsSync(filePath)) return '';
        const content = fs.readFileSync(filePath, 'utf8');
        const regex = new RegExp(`^${key}=(.*)$`, 'm');
        const match = content.match(regex);
        if (!match || typeof match[1] !== 'string') return '';
        return match[1].trim().replace(/^['"]|['"]$/g, '');
    } catch (error) {
        return '';
    }
};

const uniqueNonEmpty = (values) => {
    const seen = new Set();
    const rows = [];

    values.forEach((value) => {
        const item = String(value || '').trim();
        if (!item || seen.has(item)) return;
        seen.add(item);
        rows.push(item);
    });

    return rows;
};

const backendEnvCandidates = uniqueNonEmpty([
    process.env.LARAVEL_ENV_PATH,
    process.env.BACKEND_ENV_PATH,
    process.env.APINOTARIS_ENV_PATH,
    path.resolve(__dirname, '../backend/.env'),
    path.resolve(__dirname, '../apinotaris/.env'),
    '/var/www/backend/.env',
    '/var/www/apinotaris/.env',
]);

const readFirstEnvValue = (filePaths, key) => {
    for (const filePath of filePaths) {
        const value = readEnvValueFromFile(filePath, key);
        if (value) {
            return { value, path: filePath };
        }
    }
    return { value: '', path: '' };
};

const backendEnvMatch = readFirstEnvValue(backendEnvCandidates, 'INTERNAL_API_KEY');
const backendInternalApiKey = backendEnvMatch.value;
const backendInternalApiKeySource = backendEnvMatch.path;

const INTERNAL_API_KEY = String(
    process.env.BOT_API_KEY
    || process.env.WA_GATEWAY_API_KEY
    || process.env.API_KEY
    || backendInternalApiKey
    || 'your_super_secret_api_key_here',
).trim();

const LARAVEL_API_KEY = String(
    process.env.LARAVEL_API_KEY
    || process.env.INTERNAL_API_KEY
    || process.env.BOT_API_KEY
    || process.env.WA_GATEWAY_API_KEY
    || process.env.API_KEY
    || backendInternalApiKey
    || '',
).trim();
const LARAVEL_BASE_URL = String(
    process.env.LARAVEL_BASE_URL
    || process.env.LARAVEL_URL
    || (process.env.DOCKER_MODE === 'true' ? 'http://host.docker.internal:8000' : 'http://127.0.0.1:8000'),
).trim().replace(/\/$/, '');
const LARAVEL_API_URL_GET = `${LARAVEL_BASE_URL}/api/get-user-schedule`;
const LARAVEL_API_URL_ASSISTANTS = `${LARAVEL_BASE_URL}/api/chatbot-assistants`;
const LARAVEL_API_URL_CREATE = `${LARAVEL_BASE_URL}/api/create-event-from-chat`;
const LARAVEL_API_URL_DELETE = `${LARAVEL_BASE_URL}/api/delete-event-from-chat`;
const LARAVEL_API_URL_CLIENT_SEARCH = `${LARAVEL_BASE_URL}/api/chatbot-search-client`;
const LARAVEL_API_URL_CONFIRM_KTP_OCR = `${LARAVEL_BASE_URL}/api/chatbot/clients/confirm-ktp-ocr`;
const LARAVEL_API_URL_DOC_ACCESS_DECISION = `${LARAVEL_BASE_URL}/api/document-access/decision-from-chat`;
const LARAVEL_API_URL_REPORTORIUM_MONTHLY = `${LARAVEL_BASE_URL}/api/chatbot-reportorium-monthly`;
const KTP_OCR_BASE_URL = String(process.env.KTP_OCR_BASE_URL || 'http://127.0.0.1:8765').trim().replace(/\/$/, '');
const KTP_OCR_CONFIG = String(process.env.KTP_OCR_CONFIG || 'paddleocr-fast.json').trim();
const KTP_OCR_ENGINE = String(process.env.KTP_OCR_ENGINE || 'paddleocr').trim();
const KTP_OCR_ENDPOINT = `${KTP_OCR_BASE_URL}/api/ocr`;
const KTP_OCR_TIMEOUT_MS = Number(process.env.KTP_OCR_TIMEOUT_MS || 90000);

const WAHA_BASE_URL = String(process.env.WAHA_BASE_URL || 'http://127.0.0.1:8010').trim().replace(/\/$/, '');
const WAHA_API_KEY = String(process.env.WAHA_API_KEY || 'admin').trim();
const WAHA_SESSION = String(process.env.WAHA_SESSION || 'default').trim();
const WAHA_SEND_TEXT_ENDPOINT = String(process.env.WAHA_SEND_TEXT_ENDPOINT || '/api/sendText').trim();
const WAHA_CHECK_NUMBER_ENDPOINT = String(process.env.WAHA_CHECK_NUMBER_ENDPOINT || '/api/contacts/check-exists').trim();
const WAHA_STATUS_ENDPOINT = String(process.env.WAHA_STATUS_ENDPOINT || '/api/sessions/{session}').trim();
const WAHA_WEBHOOK_URL = String(
    process.env.WAHA_WEBHOOK_URL
    || (process.env.BOT_PUBLIC_URL ? `${String(process.env.BOT_PUBLIC_URL).trim().replace(/\/$/, '')}/webhook/waha` : ''),
).trim();
const WAHA_WEBHOOK_EVENTS = uniqueNonEmpty(
    String(process.env.WAHA_WEBHOOK_EVENTS || 'message')
        .split(',')
        .map((item) => String(item || '').trim().toLowerCase()),
);
const WAHA_WEBHOOK_AUTO_CONFIGURE = String(process.env.WAHA_WEBHOOK_AUTO_CONFIGURE || 'true').trim().toLowerCase() !== 'false';
const WAHA_WEBHOOK_SECRET = String(process.env.WAHA_WEBHOOK_SECRET || '').trim();
const USE_DUCKLING = String(process.env.USE_DUCKLING || 'false').trim().toLowerCase() === 'true';
const DUCKLING_URL = String(process.env.DUCKLING_URL || 'http://127.0.0.1:8001').trim();
const DISPLAY_TIMEZONE = String(process.env.DISPLAY_TIMEZONE || 'Asia/Jakarta').trim() || 'Asia/Jakarta';
const JAKARTA_OFFSET_HOURS = 7;

const REQUEST_TIMEOUT_MS = Number(process.env.REQUEST_TIMEOUT_MS || 15000);

app.use(bodyParser.json({ limit: '20mb' }));
app.use(express.static(path.join(__dirname, 'public')));
app.get('/', (req, res) => res.sendFile(path.join(__dirname, 'public', 'dashboard.html')));

let nlpManager;
const conversationState = {};
const lidToPhoneCache = new Map();
const processedInboundMessageIds = new Map();
const INBOUND_MESSAGE_DEDUP_TTL_MS = 10 * 60 * 1000;
const assistantDirectoryCache = {
    fetchedAt: 0,
    rows: [],
};
const inputClientSessions = {};
const INPUT_CLIENT_SESSION_TTL_MS = 15 * 60 * 1000;
const KTP_UPLOAD_DIR = path.join(__dirname, 'tmp', 'ktp-ocr');

const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

const nowInJakarta = () =>
    new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Jakarta' }));

const pad2 = (value) => String(value).padStart(2, '0');

const toSqlDateTime = (date) =>
    `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())} ${pad2(date.getHours())}:${pad2(date.getMinutes())}:00`;

const parseJakartaNaiveDateTime = ({
    year,
    month,
    day,
    hour,
    minute,
    second = 0,
    millisecond = 0,
}) => {
    // Treat datetime without timezone marker as WIB (UTC+7).
    const date = new Date(Date.UTC(year, month, day, hour - JAKARTA_OFFSET_HOURS, minute, second, millisecond));
    return Number.isNaN(date.getTime()) ? null : date;
};

const parseDateTimeSafe = (value) => {
    const source = String(value || '').trim();
    if (!source) return null;

    const naiveDateTimeMatch = source.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.(\d{1,6}))?$/);
    if (naiveDateTimeMatch) {
        const year = Number(naiveDateTimeMatch[1]);
        const month = Number(naiveDateTimeMatch[2]) - 1;
        const day = Number(naiveDateTimeMatch[3]);
        const hour = Number(naiveDateTimeMatch[4]);
        const minute = Number(naiveDateTimeMatch[5]);
        const second = Number(naiveDateTimeMatch[6] || '0');
        const micro = String(naiveDateTimeMatch[7] || '');
        const millisecond = micro ? Number(micro.slice(0, 3).padEnd(3, '0')) : 0;

        return parseJakartaNaiveDateTime({
            year,
            month,
            day,
            hour,
            minute,
            second,
            millisecond,
        });
    }

    const parsed = new Date(source);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const formatDateTimeId = (value) => {
    const date = parseDateTimeSafe(value);
    if (!date) return '-';
    return date.toLocaleString('id-ID', {
        dateStyle: 'full',
        timeStyle: 'short',
        timeZone: DISPLAY_TIMEZONE,
    });
};

const formatDateTimeRangeId = (startValue, endValue) => {
    const start = parseDateTimeSafe(startValue);
    const end = parseDateTimeSafe(endValue);
    if (!start) return '-';
    if (!end) return `${formatDateTimeId(startValue)} WIB`;

    const dateKeyFormatter = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: DISPLAY_TIMEZONE,
    });
    const endTime = end.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: DISPLAY_TIMEZONE,
    });

    if (dateKeyFormatter.format(start) === dateKeyFormatter.format(end)) {
        return `${formatDateTimeId(startValue)} - ${endTime} WIB`;
    }

    return `${formatDateTimeId(startValue)} WIB - ${formatDateTimeId(endValue)} WIB`;
};

const normalizePhone = (value) => String(value || '').replace(/[^\d]/g, '');

const phoneCandidates = (value) => {
    const phone = normalizePhone(value);
    if (!phone) return [];

    const set = new Set([phone]);

    if (phone.startsWith('62')) {
        set.add(`0${phone.slice(2)}`);
        set.add(`+${phone}`);
    } else if (phone.startsWith('0')) {
        set.add(`62${phone.slice(1)}`);
        set.add(`+62${phone.slice(1)}`);
    }

    return [...set];
};

const monthMap = {
    januari: 0,
    februari: 1,
    maret: 2,
    april: 3,
    mei: 4,
    juni: 5,
    juli: 6,
    agustus: 7,
    september: 8,
    oktober: 9,
    november: 10,
    desember: 11,
};

const normalizeUnicodeDigits = (value) =>
    String(value || '')
        .replace(/[\uFF10-\uFF19]/g, (digit) => String(digit.charCodeAt(0) - 0xFF10))
        .replace(/[\u0660-\u0669]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[\u06F0-\u06F9]/g, (digit) => String(digit.charCodeAt(0) - 0x06F0));

const normalizeScheduleDateInput = (value) =>
    normalizeUnicodeDigits(value)
        .replace(/[\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF]/g, '')
        .replace(/\u00A0/g, ' ')
        .replace(/[\u2010\u2011\u2012\u2013\u2014\u2212]/g, '-')
        .replace(/[\uFF1A\uFE55]/g, ':')
        .replace(/[\uFF0F\u2044]/g, '/')
        .replace(/[\uFF0E]/g, '.')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

const parseScheduleDateToken = (value) => {
    const source = normalizeScheduleDateInput(value);
    let year;
    let month;
    let day;

    const isoMatch = source.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/);
    const dmyMatch = source.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})$/);

    if (isoMatch) {
        year = Number(isoMatch[1]);
        month = Number(isoMatch[2]);
        day = Number(isoMatch[3]);
    } else if (dmyMatch) {
        day = Number(dmyMatch[1]);
        month = Number(dmyMatch[2]);
        year = Number(dmyMatch[3]);
        if (year < 100) year += 2000;
    } else {
        return null;
    }

    const date = new Date(year, month - 1, day);
    if (
        year < 2000
        || year > 2100
        || date.getFullYear() !== year
        || date.getMonth() !== month - 1
        || date.getDate() !== day
    ) {
        return null;
    }

    return {
        value: `${year}-${pad2(month)}-${pad2(day)}`,
        label: `${pad2(day)}-${pad2(month)}-${year}`,
        timestamp: date.getTime(),
    };
};

const resolveScheduleLookupCommand = (rawInput) => {
    const source = normalizeScheduleDateInput(rawInput);
    if (!/^(?:cek\s+)?jadwal(?:\s|$)/.test(source)) return null;
    if (/^jadwal\s+baru(?:\s|$)/.test(source)) return null;

    const dateTokens = source.match(/\b(?:\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4})\b/g) || [];
    if (dateTokens.length > 0) {
        if (dateTokens.length !== 2) {
            return {
                invalid: true,
                message: 'Rentang tanggal harus berisi tanggal mulai dan selesai. Contoh: cek jadwal 10-06-2026 sampai 20-06-2026.',
            };
        }

        const startDate = parseScheduleDateToken(dateTokens[0]);
        const endDate = parseScheduleDateToken(dateTokens[1]);
        if (!startDate || !endDate) {
            return {
                invalid: true,
                message: 'Tanggal tidak valid. Gunakan format DD-MM-YYYY atau DD/MM/YYYY.',
            };
        }

        if (endDate.timestamp < startDate.timestamp) {
            return {
                invalid: true,
                message: 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
            };
        }

        return {
            timeframe: 'range',
            start_date: startDate.value,
            end_date: endDate.value,
            label: `Jadwal Anda ${startDate.label} sampai ${endDate.label}`,
        };
    }

    if (source.includes('harian') || source.includes('hari ini') || source.includes('today')) {
        return { timeframe: 'today', label: 'Jadwal Anda Hari Ini' };
    }
    if (source.includes('bulanan') || source.includes('bulan') || source.includes('monthly') || source.includes('this month')) {
        return { timeframe: 'month', label: 'Jadwal Anda Satu Bulan ke Depan' };
    }
    if (
        source === 'jadwal'
        || source === 'cek jadwal'
        || source === 'jadwal saya'
        || source === 'cek jadwal saya'
        || source.includes('mingguan')
        || source.includes('minggu')
        || source.includes('weekly')
        || source.includes('this week')
        || source.includes('mendatang')
        || source.includes('upcoming')
        || source.includes('akan datang')
    ) {
        return { timeframe: 'week', label: 'Jadwal Anda Tujuh Hari ke Depan' };
    }

    return {
        invalid: true,
        message: 'Perintah jadwal tidak dikenali. Gunakan `cek jadwal`, `jadwal harian`, `jadwal bulanan`, atau rentang tanggal.',
    };
};

const parseTimeFromText = (text) => {
    const source = normalizeScheduleDateInput(text);
    let hour = 9;
    let minute = 0;
    let hasExplicitTime = false;

    const jamMatch = source.match(/\b(?:jam|pukul)\s*(\d{1,2})(?:\s*[:.]\s*(\d{1,2}))?\b/);
    const colonTimeMatch = source.match(/\b(\d{1,2})\s*[:.]\s*(\d{1,2})\b/);
    const plainTimeMatch = source.match(/\b(?:jam|pukul)\s*(\d{1,2})\b/);

    if (jamMatch) {
        hour = Number(jamMatch[1]);
        minute = Number(jamMatch[2] || '0');
        hasExplicitTime = true;
    } else if (colonTimeMatch) {
        hour = Number(colonTimeMatch[1]);
        minute = Number(colonTimeMatch[2] || '0');
        hasExplicitTime = true;
    } else if (plainTimeMatch) {
        hour = Number(plainTimeMatch[1]);
        minute = 0;
        hasExplicitTime = true;
    }

    if (!hasExplicitTime && !source.includes('pagi') && !source.includes('siang') && !source.includes('sore') && !source.includes('malam')) {
        return null;
    }

    if (source.includes('siang') && hour < 11) hour += 12;
    if (source.includes('sore') && hour < 12) hour += 12;
    if (source.includes('malam') && hour < 12) hour += 12;
    if (source.includes('pagi') && hour === 12) hour = 0;

    if (hour > 23 || minute > 59) return null;
    return { hour, minute };
};

const parseIndonesianDateTime = (rawInput) => {
    const input = normalizeScheduleDateInput(rawInput);
    if (!input) return null;

    const now = nowInJakarta();
    const parsedTime = parseTimeFromText(input);
    if (!parsedTime) return null;

    const isoMatch = input.match(/\b(\d{4})\s*[-/.]\s*(\d{1,2})\s*[-/.]\s*(\d{1,2})\b/);
    if (isoMatch) {
        const year = Number(isoMatch[1]);
        const month = Number(isoMatch[2]) - 1;
        const day = Number(isoMatch[3]);
        const date = new Date(year, month, day, parsedTime.hour, parsedTime.minute, 0, 0);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const dmyMatch = input.match(/\b(\d{1,2})\s*[-/.]\s*(\d{1,2})(?:\s*[-/.]\s*(\d{2,4}))?\b/);
    if (dmyMatch) {
        const day = Number(dmyMatch[1]);
        const month = Number(dmyMatch[2]) - 1;
        let year = dmyMatch[3] ? Number(dmyMatch[3]) : now.getFullYear();
        if (year < 100) year += 2000;
        const date = new Date(year, month, day, parsedTime.hour, parsedTime.minute, 0, 0);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const monthNameMatch = input.match(/\b(\d{1,2})\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)(?:\s+(\d{4}))?\b/);
    if (monthNameMatch) {
        const day = Number(monthNameMatch[1]);
        const month = monthMap[monthNameMatch[2]];
        const year = monthNameMatch[3] ? Number(monthNameMatch[3]) : now.getFullYear();
        const date = new Date(year, month, day, parsedTime.hour, parsedTime.minute, 0, 0);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const base = new Date(now);
    if (input.includes('besok')) base.setDate(base.getDate() + 1);
    else if (input.includes('lusa')) base.setDate(base.getDate() + 2);
    else if (!input.includes('hari ini')) return null;

    base.setHours(parsedTime.hour, parsedTime.minute, 0, 0);
    return base;
};

const assistantsPattern = /^(?:asisten|assistant|pihak\s*terlibat)\s*[:=]\s*/i;
const optionalSkipPattern = /^(?:-|lewati|skip|kosong|tidak ada|none|n\/a)$/i;
const isOptionalSkipValue = (value) => optionalSkipPattern.test(String(value || '').trim().toLowerCase());

const parseAssistantPhoneCandidates = (rawInput) =>
    String(rawInput || '')
        .replace(assistantsPattern, '')
        .split(/[;,]/)
        .map((item) => item.trim())
        .filter(Boolean)
        .flatMap((item) => phoneCandidates(item))
        .filter((item, index, array) => array.indexOf(item) === index);

const parseAssistantIndexSelection = (rawInput, maxIndex) => {
    const text = String(rawInput || '').toLowerCase().trim();
    if (!text || !Number.isFinite(maxIndex) || maxIndex < 1) return null;

    const normalized = text.replace(/(?:pilih|asisten|assistant|nomor|no)\s*[:=]?\s*/g, '').trim();
    if (!normalized) return null;

    const allIndexes = Array.from({ length: maxIndex }, (_, index) => index + 1);
    if (/(?:^|[\s,;])(?:semua|all|seluruh|semuanya)(?:$|[\s,;])/.test(normalized)) {
        return allIndexes;
    }

    const tokens = normalized
        .split(/[,\s;]+/)
        .map((item) => item.trim())
        .filter(Boolean);

    if (!tokens.length) return null;
    if (!tokens.every((token) => /^\d+$/.test(token) && token.length <= 3)) {
        return null;
    }

    const values = [...new Set(tokens.map((token) => Number(token)))];
    if (!values.length) {
        return null;
    }

    if (values.includes(0)) {
        const hasOutOfRangeValue = values.some((value) => value !== 0 && (value < 1 || value > maxIndex));
        if (hasOutOfRangeValue) return null;
        return allIndexes;
    }

    if (values.some((value) => value < 1 || value > maxIndex)) {
        return null;
    }

    return values;
};

const resolveAssistantSelection = (rawInput, assistantOptions = []) => {
    const source = String(rawInput || '').trim();
    if (!source || isOptionalSkipValue(source)) {
        return {
            participants_phone_candidates: [],
            participant_names: [],
        };
    }

    const selectedIndexes = parseAssistantIndexSelection(source, assistantOptions.length);
    if (selectedIndexes && selectedIndexes.length > 0) {
        const selectedAssistants = selectedIndexes
            .map((indexValue) => assistantOptions[indexValue - 1])
            .filter(Boolean);

        return {
            participants_phone_candidates: selectedAssistants
                .flatMap((assistant) => phoneCandidates(assistant.phone))
                .filter((item, index, array) => array.indexOf(item) === index),
            participant_names: selectedAssistants
                .map((assistant) => String(assistant.nama_lengkap || '').trim())
                .filter(Boolean),
        };
    }

    return {
        participants_phone_candidates: parseAssistantPhoneCandidates(source),
        participant_names: [],
    };
};

const parseScheduleForm = (rawInput, assistantOptions = []) => {
    const fields = {};
    const fieldAliases = {
        judul: 'title',
        title: 'title',
        tanggal: 'date',
        date: 'date',
        mulai: 'start_time',
        'jam mulai': 'start_time',
        selesai: 'end_time',
        'jam selesai': 'end_time',
        lokasi: 'location',
        location: 'location',
        keterangan: 'description',
        deskripsi: 'description',
        description: 'description',
        asisten: 'assistants',
        assistant: 'assistants',
        pic: 'assistants',
    };

    String(rawInput || '')
        .split(/\r?\n/)
        .forEach((line) => {
            const match = line.match(/^\s*\*?([^:]+?)\*?\s*:\s*(.*?)\s*$/);
            if (!match) return;

            const rawKey = String(match[1] || '')
                .replace(/[*_`]/g, '')
                .trim()
                .toLowerCase();
            const key = fieldAliases[rawKey];
            if (!key) return;
            fields[key] = String(match[2] || '').replace(/^[*_`]+|[*_`]+$/g, '').trim();
        });

    const requiredFields = [
        ['title', 'Judul'],
        ['date', 'Tanggal'],
        ['start_time', 'Mulai'],
        ['end_time', 'Selesai'],
    ];
    const missingFields = requiredFields
        .filter(([key]) => !String(fields[key] || '').trim())
        .map(([, label]) => label);

    if (missingFields.length > 0) {
        return {
            invalid: true,
            message: `Field wajib belum diisi: ${missingFields.join(', ')}.`,
        };
    }

    const startDate = parseIndonesianDateTime(`${fields.date} jam ${fields.start_time}`);
    const endDate = parseIndonesianDateTime(`${fields.date} jam ${fields.end_time}`);
    if (!startDate || !endDate) {
        return {
            invalid: true,
            message: 'Tanggal atau jam tidak valid. Gunakan tanggal DD-MM-YYYY dan jam HH:mm.',
        };
    }

    if (endDate.getTime() <= startDate.getTime()) {
        return {
            invalid: true,
            message: 'Jam selesai harus lebih besar dari jam mulai.',
        };
    }

    const assistantSelection = resolveAssistantSelection(fields.assistants, assistantOptions);

    return {
        title: String(fields.title).trim(),
        start_datetime: toSqlDateTime(startDate),
        end_datetime: toSqlDateTime(endDate),
        location: isOptionalSkipValue(fields.location) ? '' : String(fields.location || '').trim(),
        description: isOptionalSkipValue(fields.description) ? '' : String(fields.description || '').trim(),
        ...assistantSelection,
    };
};

const parseDocumentAccessDecisionCommand = (rawInput) => {
    const text = String(rawInput || '').trim().toLowerCase();
    if (!text) return null;

    const approveMatch = text.match(/^(?:ya|yes|setuju|acc|approve)\s+#?(\d+)(?:\s+(.+))?$/i);
    if (approveMatch) {
        return {
            requestId: Number(approveMatch[1]),
            decision: 'approved',
            note: String(approveMatch[2] || '').trim(),
        };
    }

    const rejectMatch = text.match(/^(?:tidak|no|tolak|reject)\s+#?(\d+)(?:\s+(.+))?$/i);
    if (rejectMatch) {
        return {
            requestId: Number(rejectMatch[1]),
            decision: 'rejected',
            note: String(rejectMatch[2] || '').trim(),
        };
    }

    return null;
};

const parseClientSearchCommand = (rawInput) => {
    const input = String(rawInput || '').trim();
    if (!input) return null;

    const match = input.match(/^(?:cek|cari)\s+client(?:\s+(perorangan|badan\s+hukum))?\s+(.+)$/i);
    if (!match) return null;

    const jenisRaw = String(match[1] || '').trim().toLowerCase();
    const query = String(match[2] || '').trim();
    if (!query || query.length < 2) {
        return { invalid: true };
    }

    let jenisClient = '';
    if (jenisRaw === 'perorangan') jenisClient = 'Perorangan';
    if (jenisRaw === 'badan hukum') jenisClient = 'Badan Hukum';

    return { query, jenisClient };
};

const parseDeleteScheduleCommand = (rawInput) => {
    const input = String(rawInput || '').trim();
    if (!input) return null;

    const matched = input.match(/^(?:hapus|delete)\s+jadwal\s+#?(\d+)$/i);
    if (!matched) return null;

    return { eventId: Number(matched[1]) };
};

const REPORTORIUM_MODULE_MATCHERS = [
    { path: '/buku_akta', regex: /\b(?:buku\s+)?akta\b/i },
    { path: '/buku_ppat', regex: /\b(?:buku\s+)?ppat\b/i },
    { path: '/buku_waarmerking', regex: /\b(?:buku\s+)?wa?r\s*merking\b|\bwaarmerking\b/i },
    { path: '/buku_legalisasi', regex: /\b(?:buku\s+)?legalisasi\b/i },
];

const extractReportoriumModulePaths = (input) => {
    const source = String(input || '').toLowerCase();
    if (!source) return [];

    const matched = REPORTORIUM_MODULE_MATCHERS
        .filter((item) => item.regex.test(source))
        .map((item) => item.path);

    return [...new Set(matched)];
};

const parseReportoriumMonthlyCommand = (rawInput) => {
    const input = String(rawInput || '').trim().toLowerCase();
    if (!input) return null;
    if (!/(?:laporan|report)\s+reportorium/.test(input)) {
        return null;
    }

    const now = nowInJakarta();
    let year = now.getFullYear();
    let month = now.getMonth() + 1;

    const ymMatch = input.match(/\b(\d{4})[-/](\d{1,2})\b/);
    const myMatch = input.match(/\b(\d{1,2})[-/](\d{4})\b/);

    if (ymMatch) {
        year = Number(ymMatch[1]);
        month = Number(ymMatch[2]);
    } else if (myMatch) {
        month = Number(myMatch[1]);
        year = Number(myMatch[2]);
    }

    if (month < 1 || month > 12 || year < 2000 || year > 2100) {
        return { invalid: true };
    }

    let remaining = input;
    if (ymMatch) {
        remaining = remaining.replace(ymMatch[0], ' ');
    } else if (myMatch) {
        remaining = remaining.replace(myMatch[0], ' ');
    }

    const modulePaths = extractReportoriumModulePaths(remaining);

    return {
        month: `${year}-${String(month).padStart(2, '0')}`,
        module_paths: modulePaths,
    };
};

const monthLabelId = (monthKey) => {
    const source = String(monthKey || '');
    const match = source.match(/^(\d{4})-(\d{2})$/);
    if (!match) return source;
    const year = Number(match[1]);
    const month = Number(match[2]);
    const names = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    if (month < 1 || month > 12) return source;
    return `${names[month - 1]} ${year}`;
};

const formatDateId = (value) => {
    const d = new Date(String(value || ''));
    if (Number.isNaN(d.getTime())) return '-';
    return d.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
};

const splitMessageChunks = (text, maxLength = 3000) => {
    const source = String(text || '');
    if (source.length <= maxLength) return [source];

    const lines = source.split('\n');
    const chunks = [];
    let current = '';
    lines.forEach((line) => {
        const candidate = current ? `${current}\n${line}` : line;
        if (candidate.length > maxLength) {
            if (current) chunks.push(current);
            current = line;
        } else {
            current = candidate;
        }
    });
    if (current) chunks.push(current);
    return chunks;
};

const buildAssistantSelectionMessage = (rows) => {
    const list = Array.isArray(rows) ? rows : [];
    if (!list.length) {
        return 'Daftar asisten belum tersedia. Anda bisa isi nomor WA manual (pisahkan dengan koma), atau ketik `lewati`.';
    }

    const lines = [
        'Pilih asisten dengan angka (pisahkan koma), ketik `0`/`semua` untuk semua asisten, atau isi nomor WA manual, atau ketik `lewati`.',
        'Contoh: `1,3`, `0`, `semua`, atau `081234567890, 62812...`',
        '',
        '*Daftar Asisten:*',
        '0. Semua Asisten',
    ];

    list.forEach((assistant, index) => {
        const name = String(assistant?.nama_lengkap || assistant?.name || `User ${index + 1}`).trim();
        const phone = normalizePhone(assistant?.phone || '');
        const maskedPhone = phone ? ` (${phone})` : '';
        lines.push(`${index + 1}. ${name}${maskedPhone}`);
    });

    return lines.join('\n');
};

const buildScheduleFormMessage = (rows) => {
    const now = nowInJakarta();
    const dateExample = `${pad2(now.getDate())}-${pad2(now.getMonth() + 1)}-${now.getFullYear()}`;
    const assistants = Array.isArray(rows) ? rows : [];
    const lines = [
        '*FORM JADWAL*',
        'Isi semua field wajib lalu kirim kembali pesan ini.',
        'Jam boleh fleksibel, termasuk interval 30 menit.',
        '',
        'Judul:',
        `Tanggal: ${dateExample}`,
        'Mulai: 09:00',
        'Selesai: 10:00',
        'Lokasi: lewati',
        'Keterangan: lewati',
        'Asisten: lewati',
        '',
        'Field wajib: Judul, Tanggal, Mulai, Selesai.',
        'Asisten dapat diisi nomor daftar (contoh: 1,3), `semua`, nomor WA, atau `lewati`.',
    ];

    if (assistants.length > 0) {
        lines.push('', '*Daftar Asisten:*', '0. Semua Asisten');
        assistants.slice(0, 30).forEach((assistant, index) => {
            const name = String(assistant?.nama_lengkap || `User ${index + 1}`).trim();
            lines.push(`${index + 1}. ${name}`);
        });
    }

    lines.push('', 'Ketik `batal` untuk membatalkan.');
    return lines.join('\n');
};

const getAssistantDirectory = async () => {
    const now = Date.now();
    if (assistantDirectoryCache.rows.length > 0 && (now - assistantDirectoryCache.fetchedAt) < (5 * 60 * 1000)) {
        return assistantDirectoryCache.rows;
    }

    try {
        const response = await axios.get(LARAVEL_API_URL_ASSISTANTS, {
            headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
            timeout: REQUEST_TIMEOUT_MS,
        });

        const rows = Array.isArray(response?.data?.data) ? response.data.data : [];
        const normalized = rows
            .map((row) => ({
                id: row?.id,
                id_user: row?.id_user,
                nama_lengkap: String(row?.nama_lengkap || '').trim(),
                level_user: String(row?.level_user || '').trim(),
                phone: normalizePhone(row?.phone || ''),
            }))
            .filter((row) => row.nama_lengkap && row.phone);

        assistantDirectoryCache.rows = normalized;
        assistantDirectoryCache.fetchedAt = now;
        return normalized;
    } catch (error) {
        console.error('Gagal memuat daftar asisten chatbot:', error?.response?.data || error.message);
        return [];
    }
};

const parseQuickCreateCommand = (rawInput) => {
    const input = String(rawInput || '').trim();
    const quickPattern = /^(?:buat\s+jadwal|jadwal\s+baru|\/jadwal)\s*\|\s*(.+?)\s*\|\s*(.+?)(?:\s*\|\s*(.+?))?(?:\s*\|\s*(.+?))?(?:\s*\|\s*(.+))?$/i;
    const matched = input.match(quickPattern);
    if (!matched) return null;

    const title = String(matched[1] || '').trim();
    const dateTimeText = String(matched[2] || '').trim();
    let location = String(matched[3] || '').trim();
    let description = String(matched[4] || '').trim();
    let assistantsText = String(matched[5] || '').trim();
    const parsedDate = parseIndonesianDateTime(dateTimeText);

    if (!assistantsText && assistantsPattern.test(description)) {
        assistantsText = description;
        description = '';
    }

    if (!assistantsText && assistantsPattern.test(location)) {
        assistantsText = location;
        location = '';
    }

    const participantsPhoneCandidates = parseAssistantPhoneCandidates(assistantsText);

    if (!title || !parsedDate) return { invalid: true };

    return {
        title,
        start_datetime: toSqlDateTime(parsedDate),
        end_datetime: toSqlDateTime(new Date(parsedDate.getTime() + (60 * 60 * 1000))),
        location,
        description,
        ...(participantsPhoneCandidates.length > 0 ? { participants_phone_candidates: participantsPhoneCandidates } : {}),
    };
};

const buildScheduleHelpMessage = () => {
    return [
        'Perintah cek jadwal:',
        '- *cek jadwal* (default 7 hari ke depan)',
        '- *jadwal harian* (hari ini, mulai sekarang)',
        '- *jadwal mingguan* (7 hari ke depan)',
        '- *jadwal bulanan* (1 bulan ke depan)',
        '- *cek jadwal 10-06-2026 sampai 20-06-2026*',
        '- *hapus jadwal <id>*',
        '',
        'Laporan reportorium bulanan (khusus admin):',
        '- *laporan reportorium*',
        '- *laporan reportorium per modul*',
        '- *laporan reportorium bulan ini*',
        '- *laporan reportorium 05/2026*',
        '- *laporan reportorium per modul 05/2026 akta, ppat, waarmerking, legalisasi*',
        '',
        'Cari data client:',
        '- *cek client <kata_kunci>*',
        '- *cek client perorangan <kata_kunci>*',
        '- *cek client badan hukum <kata_kunci>*',
        '',
        'Persetujuan request file (khusus admin):',
        '- *ya <id_request>* (setujui)',
        '- *tidak <id_request>* (tolak)',
        '',
        'Buat jadwal dengan form:',
        '- Kirim *buat jadwal*, lalu isi form yang diberikan dalam satu pesan.',
        '',
        'Format cepat (durasi default 1 jam):',
        '*buat jadwal | Judul | Waktu | Lokasi (opsional) | Keterangan (opsional) | Asisten: noWA1,noWA2 (opsional)*',
        '',
        'Contoh:',
        '- buat jadwal | Tanda tangan akta | besok jam 10 pagi | Kantor',
        '- buat jadwal | Meeting client A | 14/05/2026 jam 13:30',
        '- buat jadwal | Review berkas | 14/05/2026 jam 09:00 | Kantor | Dokumen prioritas | asisten: 081234567890, 6281234567890',
    ].join('\n');
};

const buildUrl = (baseUrl, endpoint) => {
    const base = String(baseUrl || '').replace(/\/+$/, '');
    const path = String(endpoint || '').replace(/^\/+/, '');
    return `${base}/${path}`;
};

const getWahaStatusEndpoint = () => {
    const source = WAHA_STATUS_ENDPOINT;
    if (source.includes('{session}')) {
        return source.replace('{session}', WAHA_SESSION);
    }

    const trimmed = source.replace(/\/+$/, '');
    if (trimmed.endsWith('/api/sessions')) {
        return `${trimmed}/${WAHA_SESSION}`;
    }

    return trimmed;
};

const wahaHeaders = () => ({
    'X-Api-Key': WAHA_API_KEY,
    Accept: 'application/json',
});

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const normalizeWebhookEvents = (events) => uniqueNonEmpty(
    (Array.isArray(events) ? events : [events])
        .flatMap((item) => String(item || '').split(','))
        .map((item) => String(item || '').trim().toLowerCase()),
);

const normalizeWebhookHeaders = (headers) => {
    if (!Array.isArray(headers)) return [];

    return headers
        .map((header) => {
            if (!isObject(header)) return null;

            const name = String(header.name || '').trim();
            const value = String(header.value || '').trim();
            if (!name || !value) return null;

            return { name, value };
        })
        .filter(Boolean);
};

const compareWebhookConfig = (webhook) => {
    const url = String(webhook?.url || '').trim();
    const events = normalizeWebhookEvents(webhook?.events).sort();
    const customHeaders = normalizeWebhookHeaders(webhook?.customHeaders)
        .map((header) => ({
            name: header.name.toLowerCase(),
            value: header.value,
        }))
        .sort((left, right) => left.name.localeCompare(right.name) || left.value.localeCompare(right.value));

    return JSON.stringify({ url, events, customHeaders });
};

const buildManagedWebhook = (existingWebhook = null) => {
    const baseHeaders = normalizeWebhookHeaders(existingWebhook?.customHeaders)
        .filter((header) => header.name.toLowerCase() !== 'x-webhook-secret');
    if (WAHA_WEBHOOK_SECRET) {
        baseHeaders.push({ name: 'X-Webhook-Secret', value: WAHA_WEBHOOK_SECRET });
    }

    const mergedEvents = uniqueNonEmpty([
        ...normalizeWebhookEvents(existingWebhook?.events),
        ...WAHA_WEBHOOK_EVENTS,
    ]);

    return {
        ...(isObject(existingWebhook) ? existingWebhook : {}),
        url: WAHA_WEBHOOK_URL,
        events: mergedEvents.length > 0 ? mergedEvents : ['message'],
        ...(baseHeaders.length > 0 ? { customHeaders: baseHeaders } : {}),
    };
};

const getWahaSessionEndpoint = () => `/api/sessions/${encodeURIComponent(WAHA_SESSION)}`;

const ensureWahaWebhook = async () => {
    if (!WAHA_WEBHOOK_AUTO_CONFIGURE) {
        console.log('WAHA webhook auto-config nonaktif (WAHA_WEBHOOK_AUTO_CONFIGURE=false).');
        return false;
    }

    if (!WAHA_WEBHOOK_URL) {
        console.log('WAHA webhook auto-config dilewati: WAHA_WEBHOOK_URL/BOT_PUBLIC_URL belum di-set.');
        return false;
    }

    try {
        const url = buildUrl(WAHA_BASE_URL, getWahaSessionEndpoint());
        const response = await axios.get(url, {
            headers: wahaHeaders(),
            timeout: REQUEST_TIMEOUT_MS,
        });

        const currentSession = isObject(response?.data) ? response.data : {};
        const currentConfig = isObject(currentSession.config) ? currentSession.config : {};
        const existingWebhooks = Array.isArray(currentConfig.webhooks)
            ? currentConfig.webhooks.filter(isObject)
            : [];
        const existingWebhook = existingWebhooks.find((item) => String(item?.url || '').trim() === WAHA_WEBHOOK_URL) || null;
        const managedWebhook = buildManagedWebhook(existingWebhook);

        const nextWebhooks = [];
        let replaced = false;
        existingWebhooks.forEach((item) => {
            if (!replaced && String(item?.url || '').trim() === WAHA_WEBHOOK_URL) {
                nextWebhooks.push(managedWebhook);
                replaced = true;
                return;
            }

            nextWebhooks.push(item);
        });

        if (!replaced) {
            nextWebhooks.push(managedWebhook);
        }

        const sameWebhookCount = existingWebhooks.filter((item) => String(item?.url || '').trim() === WAHA_WEBHOOK_URL).length;
        const currentComparable = existingWebhooks.map(compareWebhookConfig);
        const nextComparable = nextWebhooks.map(compareWebhookConfig);

        if (sameWebhookCount === 1 && JSON.stringify(currentComparable) === JSON.stringify(nextComparable)) {
            console.log(`Webhook WAHA sudah sinkron: ${WAHA_WEBHOOK_URL}`);
            return false;
        }

        const payload = {
            name: WAHA_SESSION,
            config: {
                ...currentConfig,
                webhooks: nextWebhooks,
            },
        };

        await axios.put(url, payload, {
            headers: {
                ...wahaHeaders(),
                'Content-Type': 'application/json',
            },
            timeout: REQUEST_TIMEOUT_MS,
        });

        console.log(`Webhook WAHA disinkronkan ke ${WAHA_WEBHOOK_URL} (${managedWebhook.events.join(', ')}).`);
        return true;
    } catch (error) {
        console.warn('Gagal sinkron webhook WAHA:', error?.response?.data || error.message);
        return false;
    }
};

const waitForWahaReady = async (attempts = 15, delayMs = 1000) => {
    let lastStatus = await fetchWahaStatus();

    for (let attempt = 1; attempt < attempts && lastStatus.status !== 'ready'; attempt += 1) {
        await delay(delayMs);
        lastStatus = await fetchWahaStatus();
    }

    return lastStatus;
};

const getSenderNumberFromChatId = (chatId) => {
    const raw = String(chatId || '').trim();
    if (!raw) return '';

    if (raw.includes('@')) {
        return raw.split('@')[0];
    }

    return raw;
};

const extractDigits = (value) => String(value || '').replace(/[^\d]/g, '');

const resolvePhoneFromLidChatId = async (chatId) => {
    const raw = String(chatId || '').trim();
    if (!raw.endsWith('@lid')) {
        return getSenderNumberFromChatId(raw);
    }

    const lidValue = raw.replace(/@lid$/, '');
    if (!lidValue) return getSenderNumberFromChatId(raw);

    if (lidToPhoneCache.has(lidValue)) {
        return lidToPhoneCache.get(lidValue);
    }

    try {
        const endpoint = `/api/${encodeURIComponent(WAHA_SESSION)}/lids/${encodeURIComponent(lidValue)}`;
        const url = buildUrl(WAHA_BASE_URL, endpoint);
        const response = await axios.get(url, {
            headers: wahaHeaders(),
            timeout: REQUEST_TIMEOUT_MS,
        });

        const pnRaw = String(response?.data?.pn || '').trim();
        const resolved = extractDigits(getSenderNumberFromChatId(pnRaw));
        if (resolved) {
            lidToPhoneCache.set(lidValue, resolved);
            return resolved;
        }
    } catch (error) {
        console.warn('Gagal resolve LID ke nomor:', lidValue, error?.response?.data || error.message);
    }

    return getSenderNumberFromChatId(raw);
};

const resolveMessageBody = (raw) => {
    const candidates = [
        raw?.body,
        raw?.text,
        raw?.text?.body,
        raw?.caption,
        raw?.message?.body,
        raw?.message?.text,
        raw?.message?.text?.body,
        raw?.message?.caption,
        raw?.payload?.body,
        raw?.payload?.text,
        raw?.payload?.text?.body,
        raw?.payload?.message?.body,
        raw?._data?.body,
        raw?._data?.caption,
        raw?._data?.message?.conversation,
        raw?._data?.message?.extendedTextMessage?.text,
    ];

    for (const candidate of candidates) {
        if (typeof candidate === 'string' && candidate.trim()) {
            return candidate.trim();
        }
    }

    return '';
};

const normalizeCommandText = (value) =>
    String(value || '')
        .replace(/[\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF]/g, '')
        .replace(/\u00A0/g, ' ')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

const resolveMessageFrom = (raw) => {
    const candidates = [
        raw?.from,
        raw?.chatId,
        raw?.to,
        raw?.id,
        raw?.key?.remoteJid,
        raw?._data?.from,
        raw?._data?.chatId,
    ];

    for (const candidate of candidates) {
        if (typeof candidate === 'string' && candidate.trim()) {
            const value = candidate.trim();
            if (value.includes('@')) return value;
            const phone = normalizePhone(value);
            if (phone.length >= 8) return phone;
        }
    }

    return '';
};

const isFromMeMessage = (raw) => {
    if (typeof raw?.fromMe === 'boolean') return raw.fromMe;
    if (typeof raw?.message?.fromMe === 'boolean') return raw.message.fromMe;
    if (typeof raw?._data?.id?.fromMe === 'boolean') return raw._data.id.fromMe;

    const source = String(raw?.source || raw?.message?.source || '').toLowerCase();
    if (source === 'api') return true;

    return false;
};

const resolveMessageId = (raw) => {
    const candidates = [
        raw?.id?._serialized,
        raw?.id?.id,
        raw?.id,
        raw?.key?.id,
        raw?.message?.id?._serialized,
        raw?.message?.id?.id,
        raw?.message?.id,
        raw?._data?.id?._serialized,
        raw?._data?.id?.id,
    ];

    for (const candidate of candidates) {
        if (typeof candidate === 'string' && candidate.trim()) {
            return candidate.trim();
        }
    }

    return '';
};

const reserveInboundMessage = (messageId) => {
    const id = String(messageId || '').trim();
    if (!id) return true;

    const now = Date.now();
    for (const [cachedId, cachedAt] of processedInboundMessageIds.entries()) {
        if ((now - cachedAt) > INBOUND_MESSAGE_DEDUP_TTL_MS) {
            processedInboundMessageIds.delete(cachedId);
        }
    }

    if (processedInboundMessageIds.has(id)) {
        return false;
    }

    processedInboundMessageIds.set(id, now);
    return true;
};

const releaseInboundMessage = (messageId) => {
    const id = String(messageId || '').trim();
    if (id) processedInboundMessageIds.delete(id);
};

const toChatId = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (raw.endsWith('@c.us') || raw.endsWith('@g.us') || raw.endsWith('@lid') || raw.endsWith('@newsletter')) {
        return raw;
    }

    const digits = normalizePhone(raw);
    if (!digits) return '';
    return `${digits}@c.us`;
};

const sendTextViaWaha = async ({ chatId, text }) => {
    const resolvedChatId = toChatId(chatId);
    if (!resolvedChatId) throw new Error('chatId tidak valid');

    const url = buildUrl(WAHA_BASE_URL, WAHA_SEND_TEXT_ENDPOINT);
    const payload = {
        session: WAHA_SESSION,
        chatId: resolvedChatId,
        text: String(text || ''),
    };

    await axios.post(url, payload, {
        headers: wahaHeaders(),
        timeout: REQUEST_TIMEOUT_MS,
    });
};

const hasInboundMedia = (raw) => {
    if (!isObject(raw)) return false;
    if (raw.hasMedia === true || raw.message?.hasMedia === true) return true;
    if (isObject(raw.media) || isObject(raw.file) || isObject(raw.document) || isObject(raw.image)) return true;

    const mimetype = String(raw.mimetype || raw.media?.mimetype || raw._data?.mimetype || '').toLowerCase();
    return mimetype.startsWith('image/');
};

const extractMediaDescriptor = (raw) => {
    const media = isObject(raw?.media) ? raw.media : {};
    const file = isObject(raw?.file) ? raw.file : {};
    const image = isObject(raw?.image) ? raw.image : {};
    const data = isObject(raw?._data) ? raw._data : {};

    const url = [
        media.url,
        media.mediaUrl,
        file.url,
        image.url,
        raw?.mediaUrl,
        raw?.downloadUrl,
        data.mediaUrl,
        data.deprecatedMms3Url,
    ].find((item) => typeof item === 'string' && item.trim());

    const base64 = [
        media.data,
        media.base64,
        file.data,
        image.data,
        raw?.base64,
        raw?.data,
    ].find((item) => typeof item === 'string' && item.trim());

    const mimetype = String(
        media.mimetype
        || file.mimetype
        || image.mimetype
        || raw?.mimetype
        || data.mimetype
        || 'image/jpeg'
    ).trim();

    const filename = String(
        media.filename
        || file.filename
        || image.filename
        || raw?.filename
        || data.filename
        || `ktp-${Date.now()}.${extensionFromMime(mimetype)}`
    ).trim();

    return { url, base64, mimetype, filename };
};

const extensionFromMime = (mimetype) => {
    const source = String(mimetype || '').toLowerCase();
    if (source.includes('png')) return 'png';
    if (source.includes('webp')) return 'webp';
    if (source.includes('bmp')) return 'bmp';
    if (source.includes('tif')) return 'tif';
    return 'jpg';
};

const normalizeWahaMediaUrl = (rawUrl) => {
    const source = String(rawUrl || '').trim();
    if (!source) return '';

    if (!/^https?:\/\//i.test(source)) {
        return buildUrl(WAHA_BASE_URL, source);
    }

    try {
        const parsed = new URL(source);
        const internal = new URL(WAHA_BASE_URL);
        const localHosts = ['127.0.0.1', 'localhost', '0.0.0.0'];

        if (localHosts.includes(parsed.hostname) || parsed.port === '8010') {
            parsed.protocol = internal.protocol;
            parsed.hostname = internal.hostname;
            parsed.port = internal.port;
        }

        return parsed.toString();
    } catch {
        return source;
    }
};

const downloadKtpMedia = async (msg, senderNumber) => {
    const descriptor = extractMediaDescriptor(msg.raw || {});
    const safeSender = normalizePhone(senderNumber) || 'unknown';
    const extension = extensionFromMime(descriptor.mimetype);
    const targetPath = path.join(KTP_UPLOAD_DIR, `${safeSender}-${Date.now()}.${extension}`);

    fs.mkdirSync(KTP_UPLOAD_DIR, { recursive: true });

    if (descriptor.base64) {
        const cleanBase64 = descriptor.base64.includes(',')
            ? descriptor.base64.split(',').pop()
            : descriptor.base64;
        fs.writeFileSync(targetPath, Buffer.from(cleanBase64, 'base64'));
        return targetPath;
    }

    if (descriptor.url) {
        const mediaUrl = normalizeWahaMediaUrl(descriptor.url);
        console.log(`Download media KTP dari ${mediaUrl}`);
        const response = await axios.get(mediaUrl, {
            headers: wahaHeaders(),
            responseType: 'stream',
            timeout: KTP_OCR_TIMEOUT_MS,
        });
        await new Promise((resolve, reject) => {
            const writer = fs.createWriteStream(targetPath);
            response.data.pipe(writer);
            writer.on('finish', resolve);
            writer.on('error', reject);
        });
        return targetPath;
    }

    throw new Error('Media gambar tidak ditemukan di payload webhook WAHA.');
};

const runKtpOcr = async (imagePath) => {
    const form = new FormData();
    form.append('image', fs.createReadStream(imagePath));
    form.append('config_name', KTP_OCR_CONFIG);
    form.append('engine', KTP_OCR_ENGINE);

    const response = await axios.post(KTP_OCR_ENDPOINT, form, {
        headers: form.getHeaders(),
        timeout: KTP_OCR_TIMEOUT_MS,
        maxBodyLength: Infinity,
        maxContentLength: Infinity,
    });

    return response.data;
};

const fieldValue = (ocrResult, key) => String(ocrResult?.fields?.[key]?.value || '').trim();

const buildOcrReviewMessage = ({ ocrResult, existingClient }) => {
    const lines = [];
    const exists = Boolean(existingClient);
    lines.push(exists ? '*Client sudah terdaftar.*' : '*Client belum ditemukan.*');
    if (exists) {
        lines.push(`ID: ${existingClient.id_client || '-'}`);
        lines.push(`Nama terdaftar: ${existingClient.nama_client || '-'}`);
        lines.push('');
    }

    lines.push('*Hasil OCR KTP:*');
    lines.push(`NIK: ${fieldValue(ocrResult, 'nik') || '-'}`);
    lines.push(`Nama: ${fieldValue(ocrResult, 'nama') || '-'}`);
    lines.push(`Tempat/Tgl Lahir: ${fieldValue(ocrResult, 'tempat_tanggal_lahir') || '-'}`);
    lines.push(`Jenis Kelamin: ${fieldValue(ocrResult, 'jenis_kelamin') || '-'}`);
    lines.push(`Gol. Darah: ${fieldValue(ocrResult, 'golongan_darah') || '-'}`);
    lines.push(`Alamat: ${fieldValue(ocrResult, 'alamat') || '-'}`);
    lines.push(`RT/RW: ${fieldValue(ocrResult, 'rt_rw') || '-'}`);
    lines.push(`Kel/Desa: ${fieldValue(ocrResult, 'kel_desa') || '-'}`);
    lines.push(`Kecamatan: ${fieldValue(ocrResult, 'kecamatan') || '-'}`);
    lines.push(`Agama: ${fieldValue(ocrResult, 'agama') || '-'}`);
    lines.push(`Status: ${fieldValue(ocrResult, 'status_perkawinan') || '-'}`);
    lines.push(`Pekerjaan: ${fieldValue(ocrResult, 'pekerjaan') || '-'}`);
    lines.push(`Kewarganegaraan: ${fieldValue(ocrResult, 'kewarganegaraan') || '-'}`);
    lines.push(`Berlaku Hingga: ${fieldValue(ocrResult, 'berlaku_hingga') || '-'}`);
    lines.push('');
    lines.push(exists
        ? 'Ketik *YA SIMPAN* untuk menambahkan foto KTP ke client ini.'
        : 'Ketik *YA SIMPAN* untuk membuat client baru dan menyimpan foto KTP.');
    lines.push('Ketik *BATAL* untuk membatalkan.');

    return lines.join('\n');
};

const findExistingClientByNik = async (nik) => {
    if (!nik) return null;
    const response = await axios.get(LARAVEL_API_URL_CLIENT_SEARCH, {
        params: { q: nik, jenis_client: 'Perorangan', limit: 5 },
        headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
        timeout: REQUEST_TIMEOUT_MS,
    });
    const rows = Array.isArray(response?.data?.data) ? response.data.data : [];
    return rows.find((row) => String(row.no_identitas || '').trim() === nik) || null;
};

const cleanupInputClientSessions = () => {
    const now = Date.now();
    Object.entries(inputClientSessions).forEach(([phone, session]) => {
        if (!session?.expiresAt || session.expiresAt < now) {
            delete inputClientSessions[phone];
        }
    });
};

const confirmKtpOcrToBackend = async ({ session, senderNumber }) => {
    const form = new FormData();
    form.append('ktp_image', fs.createReadStream(session.imagePath));
    form.append('ocr_result', JSON.stringify(session.ocrResult));
    form.append('source', 'whatsapp');
    form.append('sender_phone', senderNumber);

    const response = await axios.post(LARAVEL_API_URL_CONFIRM_KTP_OCR, form, {
        headers: {
            ...form.getHeaders(),
            'X-API-Key': LARAVEL_API_KEY,
            Accept: 'application/json',
        },
        timeout: REQUEST_TIMEOUT_MS,
        maxBodyLength: Infinity,
        maxContentLength: Infinity,
    });

    return response.data;
};

async function handleInputClientFlow(msg, senderNumber) {
    cleanupInputClientSessions();
    const normalized = normalizeCommandText(msg.body);
    const currentSession = inputClientSessions[senderNumber];

    if (/^input\s+(?:client|klien)$/.test(normalized)) {
        console.log(`Mode input client KTP dimulai oleh ${senderNumber}`);
        inputClientSessions[senderNumber] = {
            step: 'waiting_ktp',
            expiresAt: Date.now() + INPUT_CLIENT_SESSION_TTL_MS,
        };
        await msg.reply('Silakan kirim foto KTP client. Data tidak akan disimpan sebelum Anda konfirmasi *YA SIMPAN*.');
        return true;
    }

    if (!currentSession) {
        return false;
    }

    if (normalized === 'batal') {
        delete inputClientSessions[senderNumber];
        await msg.reply('Input client dari KTP dibatalkan.');
        return true;
    }

    if (currentSession.step === 'review') {
        if (/^(?:ya\s+simpan|simpan|ya)$/i.test(normalized)) {
            try {
                const result = await confirmKtpOcrToBackend({ session: currentSession, senderNumber });
                delete inputClientSessions[senderNumber];
                const data = result?.data || {};
                const client = data.client || {};
                const action = data.result === 'created_new' ? 'Client baru dibuat.' : 'Client sudah ada, KTP ditambahkan.';
                await msg.reply([
                    `*Berhasil.* ${action}`,
                    `ID: ${client.id_client || '-'}`,
                    `NIK: ${client.no_identitas || '-'}`,
                    `Nama: ${client.nama_client || '-'}`,
                ].join('\n'));
            } catch (error) {
                console.error('Gagal konfirmasi KTP OCR ke backend:', error?.response?.data || error.message);
                await msg.reply(error?.response?.data?.message || 'Gagal menyimpan hasil OCR KTP ke data client.');
            }
            return true;
        }

        await msg.reply('Ketik *YA SIMPAN* untuk menyimpan/attach KTP, atau *BATAL* untuk membatalkan.');
        return true;
    }

    if (currentSession.step === 'waiting_ktp') {
        if (!msg.hasMedia) {
            await msg.reply('Saya menunggu foto KTP. Silakan kirim gambar KTP, atau ketik *BATAL*.');
            return true;
        }

        try {
            await msg.reply('Foto KTP diterima. OCR sedang diproses, mohon tunggu...');
            const imagePath = await downloadKtpMedia(msg, senderNumber);
            const ocrResult = await runKtpOcr(imagePath);
            const nik = fieldValue(ocrResult, 'nik');
            const nama = fieldValue(ocrResult, 'nama');

            if (!/^\d{16}$/.test(nik) || !nama) {
                inputClientSessions[senderNumber] = {
                    step: 'waiting_ktp',
                    expiresAt: Date.now() + INPUT_CLIENT_SESSION_TTL_MS,
                };
                await msg.reply([
                    'Hasil OCR belum cukup untuk disimpan.',
                    `NIK: ${nik || '-'}`,
                    `Nama: ${nama || '-'}`,
                    '',
                    'Silakan kirim ulang foto KTP yang lebih jelas, atau ketik *BATAL*.',
                ].join('\n'));
                return true;
            }

            const existingClient = await findExistingClientByNik(nik);
            inputClientSessions[senderNumber] = {
                step: 'review',
                imagePath,
                ocrResult,
                existingClient,
                expiresAt: Date.now() + INPUT_CLIENT_SESSION_TTL_MS,
            };

            await msg.reply(buildOcrReviewMessage({ ocrResult, existingClient }));
        } catch (error) {
            console.error('Gagal proses input client KTP:', error?.response?.data || error.message);
            await msg.reply(error?.response?.data?.detail || error?.response?.data?.message || 'Gagal memproses foto KTP. Pastikan OCR service aktif dan kirim gambar yang jelas.');
        }
        return true;
    }

    return false;
}

const toMessageContext = (messageEnvelope) => {
    const eventName = String(messageEnvelope?.event || '').toLowerCase();
    const raw = isObject(messageEnvelope?.payload) ? messageEnvelope.payload : messageEnvelope;

    if (!isObject(raw)) return null;

    const from = resolveMessageFrom(raw);
    const body = resolveMessageBody(raw);
    const fromMe = isFromMeMessage(raw);
    const messageId = resolveMessageId(raw);

    return {
        eventName,
        from,
        body,
        fromMe,
        messageId,
        raw,
        hasMedia: hasInboundMedia(raw),
        reply: async (text) => {
            await sendTextViaWaha({ chatId: from, text });
        },
    };
};

const extractWebhookMessages = (payload) => {
    const results = [];

    const pushEnvelope = (entry) => {
        if (!entry) return;
        if (Array.isArray(entry)) {
            entry.forEach(pushEnvelope);
            return;
        }
        if (!isObject(entry)) return;

        if (isObject(entry.payload) && typeof entry.event === 'string') {
            results.push({ event: entry.event, payload: entry.payload });
            return;
        }

        if (Array.isArray(entry.messages)) {
            entry.messages.forEach((item) => {
                if (isObject(item)) results.push({ event: entry.event || 'message', payload: item });
            });
            return;
        }

        if (isObject(entry.message)) {
            results.push({ event: entry.event || 'message', payload: entry.message });
            return;
        }

        if (isObject(entry.payload)) {
            results.push({ event: entry.event || 'message', payload: entry.payload });
            return;
        }

        results.push({ event: entry.event || 'message', payload: entry });
    };

    pushEnvelope(payload);
    return results;
};

async function loadNlpModel() {
    const modelPath = './model.nlp';
    if (fs.existsSync(modelPath)) {
        console.log('Memuat model AI...');
        const managerConfig = {
            languages: ['id'],
            forceNER: true,
        };
        if (USE_DUCKLING) {
            managerConfig.ner = {
                useDuckling: true,
                ducklingUrl: DUCKLING_URL,
            };
        }

        nlpManager = new NlpManager(managerConfig);
        nlpManager.load(modelPath);
        console.log('Model AI berhasil dimuat.');
        if (USE_DUCKLING) {
            console.log(`Duckling aktif: ${DUCKLING_URL}`);
        } else {
            console.log('Duckling nonaktif (USE_DUCKLING=false).');
        }
    } else {
        console.error(`Error: File ${modelPath} tidak ditemukan! Harap jalankan 'node train-nlp.js'.`);
        process.exit(1);
    }
}

async function handleGetSchedule(msg, senderNumberInput = '', lookupInput = null) {
    const senderNumber = String(senderNumberInput || '').trim() || await resolvePhoneFromLidChatId(msg.from);
    const senderCandidates = phoneCandidates(senderNumber);
    const lookup = lookupInput || resolveScheduleLookupCommand(msg.body) || {
        timeframe: 'week',
        label: 'Jadwal Anda Tujuh Hari ke Depan',
    };

    if (lookup.invalid) {
        await msg.reply(lookup.message);
        return;
    }

    const { timeframe, start_date: startDate, end_date: endDate, label: replyTitle } = lookup;
    const requestParams = {
        phone: senderNumber,
        phone_candidates: senderCandidates,
        timeframe,
        ...(startDate ? { start_date: startDate } : {}),
        ...(endDate ? { end_date: endDate } : {}),
    };

    try {
        const response = await axios.get(LARAVEL_API_URL_GET, {
            params: requestParams,
            headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
            timeout: REQUEST_TIMEOUT_MS,
        });

        const data = response.data;
        if (!data || typeof data !== 'object' || Array.isArray(data)) {
            throw new Error(`Invalid schedule API response type: ${typeof data}`);
        }

        if (data.status === 'unregistered') {
            await msg.reply('Mohon maaf, nomor Anda tidak terdaftar di sistem kami.');
            return;
        }

        if (!Array.isArray(data.events)) {
            console.error('Invalid schedule payload shape:', data);
            await msg.reply('Terjadi masalah pada format data jadwal dari server. Mohon hubungi admin.');
            return;
        }

        const userName = String(data?.user?.nama_lengkap || 'Anda').trim() || 'Anda';

        if (data.events.length === 0) {
            await msg.reply(`Halo ${userName}, tidak ada *${replyTitle}*.`);
            return;
        }

        let replyMessage = `Halo ${userName}, berikut adalah *${replyTitle}*:\n\n`;
        data.events.forEach((event, index) => {
            const formattedDate = formatDateTimeRangeId(event.start_datetime, event.end_datetime);

            replyMessage += `*${index + 1}. ${event.title}*\n`;
            replyMessage += `   - ID: ${event.id}\n`;
            replyMessage += `   - Waktu: ${formattedDate}\n`;
            replyMessage += `   - Lokasi: ${event.location}\n`;
            if (event.description) replyMessage += `   - Ket: ${event.description}\n`;
            if (event.creator) replyMessage += `   - Dibuat oleh: ${event.creator.nama_lengkap}\n`;
            if (event.users && event.users.length > 0) {
                const picNames = event.users.map(user => user.nama_lengkap).join(', ');
                replyMessage += `   - PIC: ${picNames}\n`;
            }
            replyMessage += '\n';
        });

        const chunks = splitMessageChunks(replyMessage, 3500);
        for (const chunk of chunks) {
            await msg.reply(chunk);
        }
    } catch (error) {
        const status = error?.response?.status;
        const errorBody = error?.response?.data;
        const code = error?.code;
        console.error('Error di handleGetSchedule:', {
            status,
            code,
            message: error?.message,
            url: LARAVEL_API_URL_GET,
            params: requestParams,
            body: errorBody || null,
        });

        if (status === 401) {
            await msg.reply('Gagal autentikasi ke server. Cek LARAVEL_API_KEY di service bot.');
            return;
        }

        if (status === 400) {
            await msg.reply('Format permintaan jadwal belum valid. Contoh: `cek jadwal` atau `cek jadwal 10-06-2026 sampai 20-06-2026`.');
            return;
        }

        if (status === 404) {
            await msg.reply('Endpoint jadwal tidak ditemukan. Cek LARAVEL_BASE_URL pada service bot.');
            return;
        }

        if (code === 'ECONNREFUSED' || code === 'ENOTFOUND' || code === 'ETIMEDOUT') {
            await msg.reply('Koneksi bot ke server jadwal sedang bermasalah. Mohon coba lagi sebentar.');
            return;
        }

        await msg.reply('Mohon maaf, terjadi gangguan saat mengambil data jadwal Anda.');
    }
}

async function createScheduleInSystem({ senderNumber, payload, msg, forceConflict = false }) {
    const senderCandidates = phoneCandidates(senderNumber);

    const dataToPost = {
        creator_phone: senderNumber,
        creator_phone_candidates: senderCandidates,
        title: payload.title,
        start_datetime: payload.start_datetime,
        end_datetime: payload.end_datetime,
        force_conflict: forceConflict,
        ...(payload.location ? { location: payload.location } : {}),
        ...(payload.description ? { description: payload.description } : {}),
        ...(Array.isArray(payload.participants_phone_candidates) && payload.participants_phone_candidates.length > 0
            ? { participants_phone_candidates: payload.participants_phone_candidates }
            : {}),
    };

    try {
        await msg.reply('Menyimpan jadwal ke sistem...');

        const response = await axios.post(LARAVEL_API_URL_CREATE, dataToPost, {
            headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
            timeout: REQUEST_TIMEOUT_MS,
        });

        if (response.data && response.data.success) {
            await msg.reply(
                `OK, jadwal berhasil dibuat.\n`
                + `*${payload.title}*\n`
                + formatDateTimeRangeId(payload.start_datetime, payload.end_datetime)
            );
            return { success: true, conflict: false };
        }

        await msg.reply('Gagal menyimpan jadwal. Mohon cek format data Anda.');
        return { success: false, conflict: false };
    } catch (error) {
        const status = error?.response?.status;
        const apiMessage = error?.response?.data?.message;
        const conflictRows = error?.response?.data?.data?.conflicts;
        console.error('Error creating event:', status, error?.response?.data || error.message);

        if (status === 401) {
            await msg.reply('Gagal autentikasi ke server. Cek LARAVEL_API_KEY di service bot.');
            return { success: false, conflict: false };
        }

        if (status === 404) {
            await msg.reply('Nomor WhatsApp Anda belum terdaftar di sistem pengguna.');
            return { success: false, conflict: false };
        }

        if (status === 409) {
            let conflictMessage = apiMessage || 'Jadwal bentrok pada rentang waktu yang dipilih.';
            if (Array.isArray(conflictRows) && conflictRows.length > 0) {
                const lines = conflictRows
                    .slice(0, 5)
                    .map((row, index) => {
                        const title = String(row?.title || 'Agenda');
                        const rangeLabel = formatDateTimeRangeId(row?.start_datetime, row?.end_datetime);
                        return `${index + 1}. ${title} (${rangeLabel})`;
                    });
                conflictMessage += `\n\nBentrok dengan:\n${lines.join('\n')}`;
            }
            conflictMessage += '\n\nKetik *LANJUT* untuk tetap menyimpan jadwal ini, atau *BATAL* untuk membatalkan.';
            conversationState[senderNumber] = {
                step: 'awaitingConflictConfirmation',
                data: {
                    pending_payload: payload,
                },
            };
            await msg.reply(conflictMessage);
            return { success: false, conflict: true };
        }

        await msg.reply(apiMessage || 'Terjadi kesalahan teknis saat menyimpan jadwal.');
        return { success: false, conflict: false };
    }
}

async function deleteScheduleInSystem({ senderNumber, eventId, msg }) {
    const senderCandidates = phoneCandidates(senderNumber);
    const payload = {
        requester_phone: senderNumber,
        requester_phone_candidates: senderCandidates,
        event_id: eventId,
    };

    try {
        await msg.reply(`Menghapus jadwal ID ${eventId}...`);

        const response = await axios.post(LARAVEL_API_URL_DELETE, payload, {
            headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
            timeout: REQUEST_TIMEOUT_MS,
        });

        await msg.reply(String(response?.data?.message || 'Jadwal berhasil dihapus.'));
        return true;
    } catch (error) {
        const status = error?.response?.status;
        const apiMessage = error?.response?.data?.message;
        console.error('Error deleting event from chatbot:', status, error?.response?.data || error.message);

        if (status === 401) {
            await msg.reply('Gagal autentikasi ke server. Cek LARAVEL_API_KEY di service bot.');
            return false;
        }
        if (status === 403) {
            await msg.reply(apiMessage || 'Anda tidak berhak menghapus jadwal ini.');
            return false;
        }
        if (status === 404) {
            await msg.reply(apiMessage || 'ID jadwal tidak ditemukan.');
            return false;
        }
        if (status === 400) {
            await msg.reply('Format hapus jadwal tidak valid. Gunakan: `hapus jadwal <id>`');
            return false;
        }

        await msg.reply(apiMessage || 'Terjadi kesalahan teknis saat menghapus jadwal.');
        return false;
    }
}

async function handleDocumentAccessDecision({ msg, senderNumber, command }) {
    const senderCandidates = phoneCandidates(senderNumber);
    const payload = {
        admin_phone: senderNumber,
        admin_phone_candidates: senderCandidates,
        request_id: command.requestId,
        decision: command.decision,
        ...(command.note ? { note: command.note } : {}),
    };

    try {
        const response = await axios.post(LARAVEL_API_URL_DOC_ACCESS_DECISION, payload, {
            headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
            timeout: REQUEST_TIMEOUT_MS,
        });

        const data = response?.data || {};
        await msg.reply(String(data.message || 'Keputusan request file berhasil diproses.'));
        return true;
    } catch (error) {
        const status = error?.response?.status;
        const apiMessage = error?.response?.data?.message;
        console.error('Error processing document access decision:', status, error?.response?.data || error.message);

        if (status === 403) {
            await msg.reply(apiMessage || 'Nomor ini tidak berwenang memproses request file.');
            return false;
        }

        if (status === 404) {
            await msg.reply(apiMessage || 'ID request file tidak ditemukan.');
            return false;
        }

        if (status === 409) {
            await msg.reply(apiMessage || 'Request file ini sudah diproses sebelumnya.');
            return false;
        }

        await msg.reply(apiMessage || 'Gagal memproses keputusan request file.');
        return false;
    }
}

const buildClientSearchReply = (rows, command) => {
    const list = Array.isArray(rows) ? rows : [];
    if (!list.length) {
        const filterText = command.jenisClient ? ` (${command.jenisClient})` : '';
        return `Client${filterText} dengan kata kunci *${command.query}* tidak ditemukan.`;
    }

    const lines = [];
    const filterLabel = command.jenisClient ? ` (${command.jenisClient})` : '';
    lines.push(`Hasil pencarian client${filterLabel} untuk *${command.query}*:`,'');

    list.forEach((row, index) => {
        const name = String(row?.nama_client || '-');
        const jenis = String(row?.jenis_client || '-');
        const idClient = String(row?.id_client || '-');
        const identitas = String(row?.no_identitas || '-');
        const kontak = String(row?.contact_number || '-');
        const email = String(row?.email || '-');
        const pembuat = String(row?.pembuat_client || '-');
        const bantekList = Array.isArray(row?.data_bantek) ? row.data_bantek : [];

        lines.push(`*${index + 1}. ${name}*`);
        lines.push(`   - ID: ${idClient}`);
        lines.push(`   - Jenis: ${jenis}`);
        lines.push(`   - Identitas: ${identitas}`);
        lines.push(`   - Kontak: ${kontak}`);
        lines.push(`   - Email: ${email}`);
        lines.push(`   - Pembuat: ${pembuat}`);
        if (bantekList.length > 0) {
            const renderedBantek = bantekList
                .map((item) => {
                    const nomor = String(item?.no_bantek || '-');
                    const lokasi = String(item?.lokasi_bantek || '-');
                    return `${nomor} (Lokasi: ${lokasi})`;
                })
                .join('; ');
            lines.push(`   - Bantek: ${renderedBantek}`);
        } else {
            lines.push('   - Bantek: -');
        }
        lines.push('');
    });

    return lines.join('\n');
};

async function handleClientSearch({ msg, command }) {
    try {
        const response = await axios.get(LARAVEL_API_URL_CLIENT_SEARCH, {
            params: {
                q: command.query,
                ...(command.jenisClient ? { jenis_client: command.jenisClient } : {}),
                limit: 8,
            },
            headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
            timeout: REQUEST_TIMEOUT_MS,
        });

        const rows = Array.isArray(response?.data?.data) ? response.data.data : [];
        await msg.reply(buildClientSearchReply(rows, command));
    } catch (error) {
        const status = error?.response?.status;
        const apiMessage = error?.response?.data?.message;
        console.error('Error searching client from chatbot:', status, error?.response?.data || error.message);

        if (status === 401) {
            await msg.reply('Gagal autentikasi ke server. Cek LARAVEL_API_KEY di service bot.');
            return;
        }

        if (status === 422 || status === 400) {
            await msg.reply('Format pencarian client tidak valid. Contoh: `cek client andi` atau `cek client badan hukum pt`.');
            return;
        }

        await msg.reply(apiMessage || 'Terjadi gangguan saat mencari data client.');
    }
}

const buildReportoriumMonthlyReply = (payload) => {
    const data = payload && typeof payload === 'object' ? payload : {};
    const month = String(data.month || '');
    const selectedModulePaths = Array.isArray(data.selected_module_paths) ? data.selected_module_paths : [];
    const moduleSummary = Array.isArray(data.module_summary) ? data.module_summary : [];
    const assigneeSummary = Array.isArray(data.assignee_summary) ? data.assignee_summary : [];
    const rows = Array.isArray(data.rows) ? data.rows : [];
    const totalRows = Number(data.total_rows || rows.length || 0);
    const returnedRows = Number(data.returned_rows || rows.length || 0);

    const lines = [];
    lines.push(`*Laporan Reportorium Bulanan* (${monthLabelId(month)})`);
    lines.push(`Total data: ${totalRows}`);
    if (selectedModulePaths.length > 0 && moduleSummary.length > 0) {
        const selectedLabels = moduleSummary.map((item) => item.module_label || item.module_path || '-');
        lines.push(`Modul dipilih: ${selectedLabels.join(', ')}`);
    }
    lines.push('');
    lines.push('*Rekap per Modul:*');
    if (moduleSummary.length) {
        moduleSummary.forEach((item) => {
            lines.push(`- ${item.module_label || item.module_path || '-'}: ${Number(item.total || 0)}`);
        });
    } else {
        lines.push('- Tidak ada data.');
    }

    lines.push('');
    lines.push('*Rekap per Asisten:*');
    if (assigneeSummary.length) {
        assigneeSummary.slice(0, 15).forEach((item) => {
            lines.push(`- ${item.assignee_name || item.assignee_id || '-'}: ${Number(item.total || 0)}`);
        });
    } else {
        lines.push('- Tidak ada data.');
    }

    lines.push('');
    lines.push('*Detail per Modul:*');
    if (rows.length) {
        const rowsByModule = new Map();
        rows.forEach((row) => {
            const moduleKey = String(row.module_path || 'unknown');
            const moduleLabel = String(row.module_label || row.module_path || '-');
            if (!rowsByModule.has(moduleKey)) {
                rowsByModule.set(moduleKey, {
                    module_path: moduleKey,
                    module_label: moduleLabel,
                    rows: [],
                });
            }
            rowsByModule.get(moduleKey).rows.push(row);
        });

        moduleSummary.forEach((summaryItem) => {
            const modulePath = String(summaryItem.module_path || '');
            const group = rowsByModule.get(modulePath);
            const moduleLabel = summaryItem.module_label || modulePath || '-';
            const moduleTotal = Number(summaryItem.total || 0);

            lines.push('');
            lines.push(`*${moduleLabel}* (${moduleTotal})`);

            if (!group || !group.rows.length) {
                lines.push('- Tidak ada detail ditampilkan.');
                return;
            }

            group.rows.forEach((row, index) => {
                lines.push(`${index + 1}. ${row.nomor || '-'} | ${row.judul || '-'} | ${row.assignee_name || '-'} | ${formatDateId(row.tanggal || row.created_at)}`);
            });
        });

        rowsByModule.forEach((group, modulePath) => {
            const existsInSummary = moduleSummary.some((summaryItem) => String(summaryItem.module_path || '') === modulePath);
            if (existsInSummary) return;

            lines.push('');
            lines.push(`*${group.module_label}* (${group.rows.length})`);
            group.rows.forEach((row, index) => {
                lines.push(`${index + 1}. ${row.nomor || '-'} | ${row.judul || '-'} | ${row.assignee_name || '-'} | ${formatDateId(row.tanggal || row.created_at)}`);
            });
        });
    } else {
        lines.push('Tidak ada data reportorium pada bulan ini.');
    }

    if (totalRows > returnedRows) {
        lines.push('');
        lines.push(`Catatan: data ditampilkan ${returnedRows} dari total ${totalRows}.`);
    }

    return lines.join('\n');
};

async function handleReportoriumMonthly({ msg, senderNumber, command }) {
    const senderCandidates = phoneCandidates(senderNumber);
    try {
        const response = await axios.get(LARAVEL_API_URL_REPORTORIUM_MONTHLY, {
            params: {
                requester_phone: senderNumber,
                requester_phone_candidates: senderCandidates,
                month: command.month,
                ...(Array.isArray(command.module_paths) && command.module_paths.length > 0
                    ? { module_paths: command.module_paths }
                    : {}),
                limit: 500,
            },
            headers: { 'X-API-Key': LARAVEL_API_KEY, Accept: 'application/json' },
            timeout: REQUEST_TIMEOUT_MS,
        });

        const payload = response?.data?.data || {};
        const text = buildReportoriumMonthlyReply(payload);
        const chunks = splitMessageChunks(text, 3000);
        for (const chunk of chunks) {
            await msg.reply(chunk);
        }
    } catch (error) {
        const status = error?.response?.status;
        const apiMessage = error?.response?.data?.message;
        console.error('Error fetching reportorium monthly report from chatbot:', status, error?.response?.data || error.message);

        if (status === 401) {
            await msg.reply('Gagal autentikasi ke server. Cek LARAVEL_API_KEY di service bot.');
            return;
        }
        if (status === 403) {
            await msg.reply(apiMessage || 'Laporan reportorium bulanan hanya bisa diakses Admin/Super Admin.');
            return;
        }
        if (status === 422 || status === 400) {
            await msg.reply('Format bulan tidak valid. Contoh: `laporan reportorium 05/2026`.');
            return;
        }

        await msg.reply(apiMessage || 'Terjadi gangguan saat mengambil laporan reportorium bulanan.');
    }
}

async function handleCreateSchedule(msg, senderNumberInput = '') {
    const senderNumber = String(senderNumberInput || '').trim() || await resolvePhoneFromLidChatId(msg.from);
    const userMessage = msg.body.trim();

    if (!conversationState[senderNumber]) {
        const assistantOptions = await getAssistantDirectory();
        conversationState[senderNumber] = {
            step: 'awaitingScheduleForm',
            data: {
                assistant_options: assistantOptions,
            },
        };
        await msg.reply(buildScheduleFormMessage(assistantOptions));
        return;
    }

    const state = conversationState[senderNumber];

    if (userMessage.toLowerCase() === 'batal') {
        delete conversationState[senderNumber];
        await msg.reply('Pembuatan jadwal dibatalkan.');
        return;
    }

    if (state.step !== 'awaitingScheduleForm') {
        await msg.reply('Ketik *LANJUT* atau *BATAL* untuk menyelesaikan konfirmasi jadwal sebelumnya.');
        return;
    }

    const assistantOptions = Array.isArray(state.data.assistant_options) ? state.data.assistant_options : [];
    const payload = parseScheduleForm(userMessage, assistantOptions);
    if (payload.invalid) {
        await msg.reply(`${payload.message}\n\n${buildScheduleFormMessage(assistantOptions)}`);
        return;
    }

    delete conversationState[senderNumber];
    await createScheduleInSystem({ senderNumber, payload, msg });
}

async function processIncomingMessage(msg) {
    if (!nlpManager || !msg || !msg.from) return;

    if (msg.from.endsWith('@g.us') || msg.from.endsWith('@newsletter')) return;
    if (msg.fromMe) return;

    const senderNumber = await resolvePhoneFromLidChatId(msg.from);
    const userMessage = String(msg.body || '').trim();

    if (await handleInputClientFlow(msg, senderNumber)) {
        return;
    }

    if (!userMessage) return;

    if (/^\/?help$/i.test(userMessage) || /^bantuan$/i.test(userMessage)) {
        await msg.reply(buildScheduleHelpMessage());
        return;
    }

    const scheduleLookup = resolveScheduleLookupCommand(userMessage);
    if (scheduleLookup) {
        await handleGetSchedule(msg, senderNumber, scheduleLookup);
        return;
    }

    if (conversationState[senderNumber] && conversationState[senderNumber].step !== 'done') {
        const state = conversationState[senderNumber];
        const normalizedReply = userMessage.toLowerCase();

        if (state.step === 'awaitingConflictConfirmation') {
            if (/^(?:lanjut|tetap|tetap simpan|ya)$/i.test(normalizedReply)) {
                const pendingPayload = state.data.pending_payload;
                delete conversationState[senderNumber];
                await createScheduleInSystem({
                    senderNumber,
                    payload: pendingPayload,
                    msg,
                    forceConflict: true,
                });
            } else if (normalizedReply === 'batal') {
                delete conversationState[senderNumber];
                await msg.reply('Pembuatan jadwal dibatalkan.');
            } else {
                await msg.reply('Jadwal masih bentrok. Ketik *LANJUT* untuk tetap menyimpan atau *BATAL* untuk membatalkan.');
            }
        } else {
            await handleCreateSchedule(msg, senderNumber);
        }
        return;
    }

    if (/^(?:buat\s+jadwal|jadwal\s+baru|\/jadwal)$/i.test(userMessage)) {
        await handleCreateSchedule(msg, senderNumber);
        return;
    }

    const quickPayload = parseQuickCreateCommand(userMessage);
    if (quickPayload) {
        if (quickPayload.invalid) {
            await msg.reply(`Format jadwal tidak valid.\n\n${buildScheduleHelpMessage()}`);
            return;
        }

        await createScheduleInSystem({ senderNumber, payload: quickPayload, msg });
        return;
    }

    const deleteScheduleCommand = parseDeleteScheduleCommand(userMessage);
    if (deleteScheduleCommand && Number.isFinite(deleteScheduleCommand.eventId) && deleteScheduleCommand.eventId > 0) {
        await deleteScheduleInSystem({ senderNumber, eventId: deleteScheduleCommand.eventId, msg });
        return;
    }

    const reportoriumMonthlyCommand = parseReportoriumMonthlyCommand(userMessage);
    if (reportoriumMonthlyCommand) {
        if (reportoriumMonthlyCommand.invalid) {
            await msg.reply('Format bulan tidak valid. Contoh: `laporan reportorium 05/2026`.');
            return;
        }
        await handleReportoriumMonthly({ msg, senderNumber, command: reportoriumMonthlyCommand });
        return;
    }

    const clientSearchCommand = parseClientSearchCommand(userMessage);
    if (clientSearchCommand) {
        if (clientSearchCommand.invalid) {
            await msg.reply('Kata kunci client minimal 2 karakter. Contoh: `cek client andi`.');
            return;
        }
        await handleClientSearch({ msg, command: clientSearchCommand });
        return;
    }

    const docDecision = parseDocumentAccessDecisionCommand(userMessage);
    if (docDecision && Number.isFinite(docDecision.requestId) && docDecision.requestId > 0) {
        await handleDocumentAccessDecision({ msg, senderNumber, command: docDecision });
        return;
    }

    const result = await nlpManager.process('id', userMessage);
    const intent = result.intent;
    const score = Number(result.score || 0);
    console.log(`Pesan: "${msg.body}" dari ${msg.from} -> Intent: ${intent} (Score: ${score.toFixed(2)})`);

    if (score < 0.7 && intent !== 'None') {
        await msg.reply('Mohon maaf, saya kurang mengerti maksud Anda.');
        return;
    }

    switch (intent) {
        case 'schedule.get':
            await handleGetSchedule(msg, senderNumber);
            break;
        case 'schedule.create':
            await handleCreateSchedule(msg, senderNumber);
            break;
        case 'agent.greeting':
        case 'agent.thanks':
            if (result.answer) await msg.reply(result.answer);
            break;
        default:
            await msg.reply('Perintah belum dikenali. Coba kirim: `cek jadwal`, `jadwal harian`, atau `buat jadwal`.');
            break;
    }
}

const authenticateApiKey = (req, res, next) => {
    const apiKey = req.headers['x-api-key'] || req.query.api_key;
    if (!apiKey || apiKey !== INTERNAL_API_KEY) {
        return res.status(401).json({ error: 'Unauthorized: Invalid API Key.' });
    }
    next();
};

const authenticateWebhook = (req, res, next) => {
    if (!WAHA_WEBHOOK_SECRET) {
        next();
        return;
    }

    const incoming = String(req.headers['x-webhook-secret'] || req.query.secret || '').trim();
    if (!incoming || incoming !== WAHA_WEBHOOK_SECRET) {
        return res.status(401).json({ error: 'Unauthorized webhook.' });
    }

    next();
};

async function fetchWahaStatus() {
    try {
        const url = buildUrl(WAHA_BASE_URL, getWahaStatusEndpoint());
        const response = await axios.get(url, {
            headers: wahaHeaders(),
            timeout: REQUEST_TIMEOUT_MS,
        });

        const data = response.data;
        const sessionStatus = String(data?.status || '').toUpperCase();
        const isReady = ['WORKING', 'CONNECTED', 'AUTHENTICATED'].includes(sessionStatus);

        return {
            status: isReady ? 'ready' : 'not ready',
            session_status: sessionStatus || 'UNKNOWN',
            session: WAHA_SESSION,
            qr_code: null,
            raw: data,
        };
    } catch (error) {
        return {
            status: 'not ready',
            session_status: 'UNKNOWN',
            session: WAHA_SESSION,
            qr_code: null,
            error: error?.response?.data || error.message,
        };
    }
}

app.get('/status', authenticateApiKey, async (req, res) => {
    const status = await fetchWahaStatus();
    res.status(200).json(status);
});

app.post('/send-message', authenticateApiKey, async (req, res) => {
    const { number, message } = req.body;
    if (!number || !message) {
        return res.status(400).json({ error: 'Parameter "number" dan "message" diperlukan.' });
    }

    try {
        await sendTextViaWaha({ chatId: number, text: message });
        const chatId = toChatId(number);
        console.log(`Pesan terkirim ke ${chatId} via WAHA.`);
        res.status(200).json({ success: true, message: 'Pesan berhasil dikirim.' });
    } catch (error) {
        console.error('Error saat mengirim pesan via WAHA:', error?.response?.data || error.message);
        res.status(500).json({
            error: 'Gagal mengirim pesan.',
            details: error?.response?.data || error.message,
        });
    }
});

app.get('/is-registered', authenticateApiKey, async (req, res) => {
    const { number } = req.query;
    if (!number) {
        return res.status(400).json({ error: 'Parameter "number" diperlukan.' });
    }

    try {
        const url = buildUrl(WAHA_BASE_URL, WAHA_CHECK_NUMBER_ENDPOINT);
        const response = await axios.get(url, {
            headers: wahaHeaders(),
            params: {
                phone: normalizePhone(String(number || '')),
                session: WAHA_SESSION,
            },
            timeout: REQUEST_TIMEOUT_MS,
        });

        const data = response.data || {};
        const isRegistered = Boolean(data.numberExists);

        res.status(200).json({
            status: true,
            message: isRegistered ? 'Nomor terdaftar' : 'Nomor tidak terdaftar',
            data: {
                registered: isRegistered,
                phone: number,
                user_exists: isRegistered,
                chat_id: data.chatId || null,
            },
            raw: data,
        });
    } catch (error) {
        console.error('Error saat memeriksa nomor via WAHA:', error?.response?.data || error.message);
        res.status(500).json({
            error: 'Gagal mengecek nomor.',
            details: error?.response?.data || error.message,
        });
    }
});

app.post('/webhook/waha', authenticateWebhook, async (req, res) => {
    try {
        const envelopes = extractWebhookMessages(req.body);
        if (!envelopes.length) {
            return res.status(200).json({ ok: true, processed: 0 });
        }

        let processed = 0;

        for (const envelope of envelopes) {
            const context = toMessageContext(envelope);
            if (!context) continue;

            if (!context.eventName.startsWith('message')) continue;
            if (!context.from) continue;
            if (!context.body && !context.hasMedia) continue;
            if (!reserveInboundMessage(context.messageId)) {
                console.log(`Webhook duplikat diabaikan: ${context.messageId}`);
                continue;
            }

            try {
                await processIncomingMessage(context);
                processed += 1;
            } catch (error) {
                releaseInboundMessage(context.messageId);
                console.error('Error saat memproses inbound message:', error?.response?.data || error.message);
            }
        }

        return res.status(200).json({ ok: true, processed });
    } catch (error) {
        console.error('Webhook processing error:', error?.response?.data || error.message);
        return res.status(500).json({ ok: false, error: 'webhook processing failed' });
    }
});

app.listen(port, async () => {
    console.log(`Server Chatbot WAHA berjalan di http://localhost:${port}`);
    console.log(`Laravel Base URL: ${LARAVEL_BASE_URL}`);
    console.log(`WAHA Base URL: ${WAHA_BASE_URL}, session: ${WAHA_SESSION}`);
    if (WAHA_WEBHOOK_URL) {
        console.log(`WAHA Webhook URL: ${WAHA_WEBHOOK_URL}`);
    }
    if (backendInternalApiKeySource) {
        console.log(`INTERNAL_API_KEY source: ${backendInternalApiKeySource}`);
    } else {
        console.log('INTERNAL_API_KEY source: process.env only');
    }

    if (!LARAVEL_API_KEY) {
        console.warn('PERINGATAN: LARAVEL_API_KEY belum di-set. Fitur baca/buat jadwal chatbot akan gagal (401).');
    }

    if (INTERNAL_API_KEY === 'your_super_secret_api_key_here') {
        console.warn('PERINGATAN: BOT_API_KEY/WA_GATEWAY_API_KEY/API_KEY masih default. Endpoint internal bot tidak aman.');
    }

    await loadNlpModel();

    const webhookUpdated = await ensureWahaWebhook();
    const wahaStatus = webhookUpdated ? await waitForWahaReady() : await fetchWahaStatus();
    console.log(`Status WAHA awal: ${wahaStatus.status} (${wahaStatus.session_status})`);
});
