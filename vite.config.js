import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

/*
 * Nextcloud loads app scripts with a plain <script src> tag, so every bundle
 * has to be a self-contained IIFE. Rollup emits one IIFE per build, and the
 * app ships two independent front ends, so `npm run build` runs this config
 * once per entry point and selects the entry through the Vite mode.
 */
const entries = {
  admin: resolve(import.meta.dirname, 'src/admin.js'),
  search: resolve(import.meta.dirname, 'src/search.js'),
};

export default defineConfig(({ mode }) => {
  const entry = entries[mode];
  if (!entry) {
    throw new Error(`Unknown build mode "${mode}". Use one of: ${Object.keys(entries).join(', ')}.`);
  }

  return {
    plugins: [vue()],
    define: {
      // @nextcloud/vue and Vue itself branch on this at build time.
      'process.env.NODE_ENV': JSON.stringify('production'),
      __VUE_OPTIONS_API__: 'true',
      __VUE_PROD_DEVTOOLS__: 'false',
      __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
    },
    build: {
      cssCodeSplit: false,
      outDir: 'build/frontend',
      emptyOutDir: false,
      target: 'es2022',
      rollupOptions: {
        input: entry,
        output: {
          format: 'iife',
          entryFileNames: `${mode}.js`,
          assetFileNames: `${mode}.[ext]`,
          inlineDynamicImports: true,
        },
      },
    },
  };
});
