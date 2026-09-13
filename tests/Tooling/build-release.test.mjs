import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, copyFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';

test('release archive excludes development files, verifies version and checksum', () => {
  const dir = mkdtempSync(join(tmpdir(), 'mec-package-test-'));
  const run = (cmd, args, env = {}) => execFileSync(cmd, args, { cwd: dir, encoding: 'utf8', env: { ...process.env, ...env } });
  try {
    for (const folder of ['appinfo', 'lib/Controller', 'scripts', 'tests', 'docs', '.codex', 'src', 'js']) mkdirSync(join(dir, folder), { recursive: true });
    writeFileSync(join(dir, 'appinfo/info.xml'), '<info><id>media_embedding_connector</id><version>1.2.3</version></info>');
    writeFileSync(join(dir, 'js/search.js'), 'console.log("committed");');
    for (const name of ['AdminController.php', 'SearchController.php']) copyFileSync(new URL('../../lib/Controller/' + name, import.meta.url), join(dir, 'lib/Controller', name));
    for (const path of ['tests/example.js', 'docs/private.md', '.codex/private.md', 'src/example.js', 'vitest.config.js']) writeFileSync(join(dir, path), 'development only');
    copyFileSync(new URL('../../scripts/build-release.sh', import.meta.url), join(dir, 'scripts/build-release.sh'));
    copyFileSync(new URL('../../scripts/check-controller-files.php', import.meta.url), join(dir, 'scripts/check-controller-files.php'));
    run('git', ['init', '-q']);
    run('git', ['add', '.']);
    run('git', ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-qm', 'Fixture']);
    run('bash', ['scripts/build-release.sh'], { RELEASE_TAG: 'v1.2.3' });
    const file = 'media_embedding_connector-1.2.3.tar.gz';
    const contents = run('tar', ['-tzf', 'build/' + file]);
    assert.match(contents, /media_embedding_connector\/js\/search.js/);
    assert.doesNotMatch(contents, /\/(\.codex|tests|docs|src|scripts)\/|vitest.config/);
    const hash = createHash('sha256').update(readFileSync(join(dir, 'build', file))).digest('hex');
    assert.ok(readFileSync(join(dir, 'build', file + '.sha256'), 'utf8').startsWith(hash));
    assert.throws(() => run('bash', ['scripts/build-release.sh'], { RELEASE_TAG: 'v9.9.9' }));
    assert.equal(createHash('sha256').update(readFileSync(join(dir, 'build', file))).digest('hex'), hash);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
