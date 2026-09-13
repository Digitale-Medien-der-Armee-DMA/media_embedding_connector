# Release and Nextcloud App Signing

## Public source release

The canonical repository and release assets are hosted at
`https://github.com/Digitale-Medien-der-Armee-DMA/media_embedding_connector`. Release archives must
remain anonymously downloadable over HTTPS.

1. Update the version in `appinfo/info.xml`, `package.json`, and
   `package-lock.json`, and move the unreleased changelog into a version section.
2. Commit and push the release changes.
3. Create and push a version tag:

   ```bash
   git tag -a vX.Y.Z -m "Media Embedding Connector vX.Y.Z"
   git push origin vX.Y.Z
   ```

4. The release workflows validate the tag, build the archive, and attach the
   `.tar.gz` plus its SHA-256 file to the matching GitHub release.
5. Download the `.tar.gz` and checksum files from the GitHub release page.

GitHub uses a single CI workflow. On pushed version tags, publication runs
only after all validation, security, and integration jobs have succeeded. The
package job builds and verifies the archive with `scripts/build-release.sh`,
then publishes those same files. Retry a failed tagged run from the Actions UI.

The archive must contain exactly:

```text
media_embedding_connector/
  appinfo/
  lib/
  templates/
  js/
  css/
  ...
```

Do not package or copy Finder metadata into the app directory. Files named
`._*.php` are interpreted as PHP classes by Nextcloud's attribute-route scanner
and can make the whole server unavailable while the app is enabled. The release
workflow excludes `.DS_Store`, `._*`, and `__MACOSX` entries and fails if any of
them remain in the staged app. It also verifies that every file seen by
Nextcloud's controller scanner declares the class implied by its filename.

Transfer the `.tar.gz` archive to the Nextcloud host as a single binary file and
extract it there. Do not extract the release on macOS and then copy the resulting
directory to the server, because that transfer can recreate AppleDouble files
even when the original archive was clean.

When an app directory was transferred from macOS, remove these metadata files
on the Nextcloud host before enabling the app:

```bash
find /path/to/nextcloud/apps/media_embedding_connector \
  \( -name '._*' -o -name '.DS_Store' \) -type f -delete
find /path/to/nextcloud/apps/media_embedding_connector \
  -type d -name '__MACOSX' -prune -exec rm -rf -- {} +
```

## Integrity signing

Every App Store upload needs a detached archive signature. The internal
`occ integrity:sign-app` signature is mandatory for Featured apps and must be
continued after it has been used once. This project prepares both signatures;
internal signing is also recommended for controlled private production
distribution.

Nextcloud app signing is tied to the exact app ID:

```text
media_embedding_connector
```

### Obtain a certificate

1. Generate and securely store a private key plus certificate signing request
   for the exact app ID:

   ```bash
   openssl req -nodes -newkey rsa:4096 \
     -keyout media_embedding_connector.key \
     -out media_embedding_connector.csr \
     -subj "/CN=media_embedding_connector"
   ```

2. Submit the CSR through the Nextcloud app certificate process:

   ```text
   https://github.com/nextcloud/app-certificate-requests
   ```

   The request needs to reference the public app repository.

3. Keep the returned certificate and private key outside Git.

The private key must never be committed or uploaded as a normal workflow
artifact.

### Sign the app directory

Place the final app directory in a Nextcloud installation, or point `--path`
at the final staging directory, then run from the Nextcloud server root:

```bash
sudo -u www-data php occ integrity:sign-app \
  --privateKey=/secure/path/media_embedding_connector.key \
  --certificate=/secure/path/media_embedding_connector.crt \
  --path=/path/to/nextcloud/apps/media_embedding_connector
```

This creates:

```text
appinfo/signature.json
```

Do not modify any app file after signing. Any change invalidates the integrity
signature and requires signing again.

Build the `.tar.gz` only after `signature.json` exists.

The App Store also requires a detached SHA-512 signature of the completed
archive. This is distinct from the internal `appinfo/signature.json`:

```bash
openssl dgst -sha512 \
  -sign /secure/path/media_embedding_connector.key \
  /path/to/media_embedding_connector-X.Y.Z.tar.gz \
  | openssl base64 -A
```

## App Store approval

Code signing and App Store approval are separate steps:

- Signing proves that a released app archive was produced by the app maintainer
  and has not been changed after signing.
- App Store approval is the public distribution review on apps.nextcloud.com.

For private on-prem distribution, a signed release archive is enough if the
customer deployment accepts manually installed apps. For public App Store
distribution, prepare the app listing, screenshots, release notes, license,
support links, and pass the Nextcloud app guidelines. Apps published through
apps.nextcloud.com must be signed.

For submission, register `media_embedding_connector` at `apps.nextcloud.com`
using its certificate and a private-key signature over the app ID. Provide the
public HTTPS URL of the final signed archive and its detached signature. Include
the supported Nextcloud versions, description, approved screenshots, license,
support contact, and release notes. Verify that the archive can be downloaded
without authentication. The current GitHub package workflow does not perform
App Store signing or submission.
