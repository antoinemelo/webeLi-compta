import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  base: './',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  build: {
    outDir: '../../public/app',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    target: 'es2022'
  },
  server: {
    proxy: {
      '/api/v1': 'http://127.0.0.1:8080'
    }
  }
});
