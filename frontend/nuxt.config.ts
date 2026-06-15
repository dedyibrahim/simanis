import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  app: {
    head: {
      titleTemplate: '%s | SIMANIS',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        {
          name: 'description',
          content: 'Frontend Nuxt baru untuk SIMANIS dengan UI admin modern berbasis Tailwind.',
        },
      ],
      link: [
        { rel: 'icon', type: 'image/svg+xml', href: '/simanis-logo.svg' },
      ],
    },
  },
  runtimeConfig: {
    public: {
      appName: 'SIMANIS - Sistem Informasi Administrasi Kantor Notaris',
      apiBase: 'http://127.0.0.1:8000/api',
      assetBase: 'http://127.0.0.1:8000',
    },
  },
  vite: {
    plugins: [tailwindcss()],
  },
})
