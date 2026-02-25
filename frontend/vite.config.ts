import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icon.svg'],
      manifest: {
        name: 'OnLedge',
        short_name: 'OnLedge',
        description: 'Receipt capture, search, and export with offline support',
        start_url: '/',
        display: 'standalone',
        background_color: '#f4f7f5',
        theme_color: '#0f2f3a',
        icons: [
          {
            src: '/icon.svg',
            sizes: 'any',
            type: 'image/svg+xml',
            purpose: 'any maskable'
          }
        ]
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,ico,png}'],
        // OAuth uses top-level browser navigations to /api/auth/oauth/*.
        // Never rewrite API navigations to SPA index.html.
        navigateFallbackDenylist: [/^\/api\//]
      }
    })
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  build: {
    outDir: '../deploy/public_html',
    emptyOutDir: true,
    sourcemap: false
  }
});
