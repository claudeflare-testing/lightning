import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Output in `out/` per allinearsi al pipeline r5 (Cloudflare Pages).
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'out',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    port: 5173,
    // Proxy verso l'API PHP in dev, cosi' niente grane CORS lato REST.
    proxy: {
      '/api': 'http://localhost:8080',
    },
  },
})
