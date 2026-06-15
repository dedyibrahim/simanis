# Frontend SIMANIS

Frontend baru untuk migrasi `newfrontend` ke stack modern:

- `Nuxt 4`
- `Tailwind CSS 4`
- `Vue 3`
- pola UI admin baru yang mengikuti struktur modul SIMANIS lama

## Baseline

- Node minimum untuk Nuxt 4: `20.x` atau lebih baru.
- Target LTS yang direkomendasikan untuk proyek ini: lihat `.nvmrc`.
- Backend API default: `http://127.0.0.1:8000/api`

## Setup

```bash
npm install
```

Salin env bila perlu:

```bash
copy .env.example .env
```

## Development

```bash
npm run dev
```

## Build

```bash
npm run build
```

## Struktur

- `app/layouts`: shell aplikasi dan layout auth
- `app/pages`: login, dashboard, dan route modul
- `app/data`: peta modul dan navigasi dari aplikasi lama
- `app/composables`: session dan wrapper API

## Catatan Migrasi

- Folder `newfrontend` tidak disentuh.
- Route utama lama sudah dipetakan ke route Nuxt baru.
- Login email/password sudah disiapkan untuk endpoint Laravel `/auth/login`.
- Halaman modul dibuat sebagai shell migrasi agar porting fitur dapat dilakukan bertahap per modul.
