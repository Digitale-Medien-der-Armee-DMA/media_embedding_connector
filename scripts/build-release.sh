#!/usr/bin/env bash
set -euo pipefail

# Build only committed files. CI verifies generated assets before this step.
cd "$(git rev-parse --show-toplevel)"
app_id="$(php -r '$x=simplexml_load_file("appinfo/info.xml"); echo (string)$x->id;')"
version="$(php -r '$x=simplexml_load_file("appinfo/info.xml"); echo (string)$x->version;')"
[[ "$app_id" =~ ^[a-z][a-z0-9_]+$ && "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
if [[ -n "${RELEASE_TAG:-}" ]]; then
  test "$RELEASE_TAG" = "v$version"
fi
stage="$(mktemp -d "${TMPDIR:-/tmp}/mec-release.XXXXXX")"
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/$app_id" build
git archive --format=tar HEAD | tar -x -C "$stage/$app_id" \
  --exclude='._*' --exclude='.DS_Store' --exclude='__MACOSX' \
  --exclude='.gitignore' --exclude='.gitea' --exclude='.github' \
  --exclude='.claude' --exclude='.codex' --exclude='docs' --exclude='.phpunit.cache' \
  --exclude='build' --exclude='composer.json' --exclude='composer.lock' \
  --exclude='node_modules' --exclude='package.json' --exclude='package-lock.json' \
  --exclude='scripts' --exclude='src' --exclude='tests' --exclude='vendor' \
  --exclude='vite.config.js' --exclude='vitest.config.js' \
  --exclude='phpunit.xml' --exclude='psalm.xml' --exclude='psalm-stubs'
php scripts/check-controller-files.php "$stage/$app_id/lib/Controller"
test "$(php -r '$x=simplexml_load_file($argv[1]); echo (string)$x->version;' "$stage/$app_id/appinfo/info.xml")" = "$version"
archive="$app_id-$version.tar.gz"
COPYFILE_DISABLE=1 tar --format=ustar --no-xattrs --no-acls \
  -czf "$stage/$archive" -C "$stage" "$app_id"
gzip -t "$stage/$archive"
php scripts/check-release-metadata.php "$stage/$archive"
if tar -tzf "$stage/$archive" | grep -E '(^|/)(\.codex|docs|tests|src|vendor|node_modules|vitest.config.js|\.DS_Store|__MACOSX)(/|$)|/\._'; then
  echo 'Unexpected development files or metadata in release archive' >&2
  exit 1
fi
mv "$stage/$archive" "build/$archive"
(cd build && shasum -a 256 "$archive" > "$archive.sha256" && shasum -a 256 -c "$archive.sha256")
