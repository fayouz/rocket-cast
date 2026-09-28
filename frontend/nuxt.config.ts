import { fileURLToPath } from 'node:url'

// Kiosk and pairing pages: rendered in the browser only (offline cache in localStorage), never cached, never indexed.
const publicScreenHeaders = { 'Cache-Control': 'no-store', 'X-Robots-Tag': 'noindex, nofollow', 'Referrer-Policy': 'no-referrer' }

export default defineNuxtConfig({
  // The Rocket core layer (npm "@rocket/core", from GitHub): layout, dashboard, accounts, applications, updates…
  // ROCKET_CORE_LAYER: a local checkout of rocket-core/nuxt, to work on both at once.
  extends: [process.env.ROCKET_CORE_LAYER || fileURLToPath(new URL('./node_modules/@rocket/core/nuxt', import.meta.url))],
  compatibilityDate: '2026-09-01',
  modules: ['@nuxt/eslint'],
  app: {
    head: {
      title: 'Rocket Cast',
    },
  },
  routeRules: {
    '/s/**': { ssr: false, headers: publicScreenHeaders },
    '/pair': { ssr: false, headers: publicScreenHeaders },
  },
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8600',
      // Dashboard shortcuts.
      docsUrl: 'https://github.com/fayouz/rocket-cast/tree/develop/docs/content',
      changelogUrl: 'https://github.com/fayouz/rocket-cast/blob/develop/CHANGELOG.md',
    },
  },
})
