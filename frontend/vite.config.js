import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      // Where /api goes. Defaults to a Laravel dev server on the host, but the
      // Vite server also runs inside Docker, where nothing listens on 8000 and
      // the API is reached through nginx instead (see API_PROXY_TARGET in
      // docker-compose.yml). Without this the OAuth callback dies with a 502.
      '/api': {
        target: process.env.API_PROXY_TARGET || 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/react-icons')) return 'icons';
          if (id.includes('node_modules/react') || id.includes('node_modules/react-dom') || id.includes('node_modules/react-router') || id.includes('node_modules/framer-motion')) return 'vendor';
        },
      },
    },
  },
})
