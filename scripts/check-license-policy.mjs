import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const packageJson = JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8'));
const packageLock = JSON.parse(readFileSync(resolve(root, 'package-lock.json'), 'utf8'));
const composerJson = JSON.parse(readFileSync(resolve(root, 'composer.json'), 'utf8'));
const infoXml = readFileSync(resolve(root, 'appinfo/info.xml'), 'utf8');

const projectLicense = 'AGPL-3.0-or-later';
// Runtime dependencies must be explicitly approved here in addition to using
// an allowed license. The admin settings and the search interface are built
// with the official Nextcloud Vue component library, so its packages ship in
// the distributed bundles alongside project-owned code.
const approvedRuntimeDependencies = new Map([
  ['@nextcloud/auth', '^2.6.0'],
  ['@nextcloud/initial-state', '^3.0.0'],
  ['@nextcloud/l10n', '^3.4.1'],
  ['@nextcloud/vue', '^9.11.0'],
  ['vue', '^3.5.42'],
  ['vue-material-design-icons', '^5.3.1'],
]);
const allowedDependencyLicenses = new Set([
  '(MPL-2.0 OR Apache-2.0)',
  '0BSD',
  'AGPL-3.0-or-later',
  'Apache-2.0',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'BlueOak-1.0.0',
  'GPL-3.0-or-later',
  'ISC',
  'MIT',
]);

const errors = [];
if (packageJson.license !== projectLicense) {
  errors.push(`package.json must declare ${projectLicense}`);
}
if (composerJson.license !== projectLicense) {
  errors.push(`composer.json must declare ${projectLicense}`);
}
if (!infoXml.includes(`<licence>${projectLicense}</licence>`)) {
  errors.push(`appinfo/info.xml must declare ${projectLicense}`);
}

const declaredRuntimeDependencies = packageJson.dependencies || {};
for (const [name, versionRange] of Object.entries(declaredRuntimeDependencies)) {
  const approvedVersionRange = approvedRuntimeDependencies.get(name);
  if (approvedVersionRange === undefined) {
    errors.push(`${name}@${versionRange} is a runtime dependency without explicit policy approval`);
  } else if (versionRange !== approvedVersionRange) {
    errors.push(`${name} must use approved runtime version range ${approvedVersionRange}, found ${versionRange}`);
  }
}
for (const [name, versionRange] of approvedRuntimeDependencies) {
  if (declaredRuntimeDependencies[name] !== versionRange) {
    errors.push(`${name}@${versionRange} is approved but not declared exactly in package.json`);
  }
}

const productionPackages = Object.entries(packageLock.packages || {})
  .filter(([path, metadata]) => path.startsWith('node_modules/') && metadata.dev !== true)
  .map(([path, metadata]) => ({
    name: path.slice('node_modules/'.length),
    version: metadata.version || 'unknown',
    license: metadata.license,
  }));

for (const dependency of productionPackages) {
  if (typeof dependency.license !== 'string' || dependency.license.trim() === '') {
    errors.push(`${dependency.name}@${dependency.version} has no declared license`);
    continue;
  }
  if (!allowedDependencyLicenses.has(dependency.license)) {
    errors.push(`${dependency.name}@${dependency.version} uses unapproved license ${dependency.license}`);
  }
}

if (errors.length > 0) {
  for (const error of errors) {
    process.stderr.write(`License policy error: ${error}\n`);
  }
  process.exit(1);
}

const licenses = [...new Set(productionPackages.map(({ license }) => license))].sort();
const licenseSummary = licenses.length > 0 ? licenses.join(', ') : 'none';
process.stdout.write(
  `License policy passed for ${productionPackages.length} production npm packages: ${licenseSummary}\n`,
);
