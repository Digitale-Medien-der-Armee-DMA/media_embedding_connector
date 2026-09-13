import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const buildDirectory = resolve(root, 'build/frontend');

/*
 * Vite writes each bundle plus its extracted stylesheet into build/frontend.
 * Nextcloud serves scripts from js/ and stylesheets from css/, and both
 * directories are committed so releases do not require a Node toolchain.
 */
const artifacts = [
  { from: 'admin.js', to: 'js/admin.js' },
  { from: 'search.js', to: 'js/search.js' },
  { from: 'admin.css', to: 'css/admin.css' },
  { from: 'search.css', to: 'css/search.css' },
];

mkdirSync(resolve(root, 'js'), { recursive: true });
mkdirSync(resolve(root, 'css'), { recursive: true });

for (const { from, to } of artifacts) {
  const source = resolve(buildDirectory, from);
  if (!existsSync(source)) {
    throw new Error(`Build artifact ${from} is missing. Run "npm run build".`);
  }
  copyFileSync(source, resolve(root, to));
}

rmSync(buildDirectory, { recursive: true, force: true });
