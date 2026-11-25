import tailwindcss from '@tailwindcss/vite'

const loopbackUrl = /^https?:\/\/(?:localhost|127(?:\.\d{1,3}){3}|\[::1\])(?::|\/|$)/i

if (process.argv.includes('generate')) {
  for (const name of ['NUXT_PUBLIC_API_BASE', 'NUXT_PUBLIC_ASSET_BASE']) {
    const value = process.env[name]
    if (value && loopbackUrl.test(value)) {
      throw new Error(`${name} points to localhost; set a reachable production URL before generating static assets.`)
    }
  }
}

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: false },
  routeRules: {
    '/dashboard': { ssr: false },
    '/settings-whatsapp-broadcast': { ssr: false },
  },
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
      apiBase: 'http://192.168.0.12:8000/api',
      assetBase: 'http://192.168.0.12:8000',
    },
  },
  vite: {
    plugins: [tailwindcss()],
  },
})
