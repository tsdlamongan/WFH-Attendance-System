import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { copyFileSync } from 'fs'
import { join } from 'path'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    {
      name: 'copy-htaccess',
      closeBundle() {
        // Copy .htaccess to dist folder after build
        try {
          copyFileSync(
            join(__dirname, '.htaccess'),
            join(__dirname, 'dist', '.htaccess')
          )
          console.log('✓ .htaccess copied to dist folder')
        } catch (err) {
          console.warn('- .htaccess not found, make sure to copy it manually')
        }
      }
    }
  ],
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
  },
})
