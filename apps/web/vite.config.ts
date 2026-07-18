import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';

// Styling is Mantine (+ PostCSS via postcss.config.cjs), no Tailwind.
// Dev: proxy API calls to the Laravel backend (php artisan serve on :8000).
// Prod: the built SPA is copied into apps/api/public and served by Laravel.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
        // Admin auth is a same-origin session cookie (Sanctum SPA + CSRF). Rewrite the
        // cookie's Domain attribute so it round-trips through the Vite dev proxy.
        cookieDomainRewrite: '',
      },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
});
