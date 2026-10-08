import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: 'Assets/dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    lib: {
      entry: 'Assets/js/src/index.tsx',
      name: 'LeuchtfeuerMediaAssetsBasic',
      formats: ['iife'],
      fileName: () => 'picker.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'picker.[ext]',
      },
    },
  },
});
