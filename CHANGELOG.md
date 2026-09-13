# Changelog

All notable changes to Media Embedding Connector are documented here. Versions follow
Semantic Versioning.

## Unreleased

- Restore uploaded query images from tab session storage when returning from Files (up to 3 MiB, subject to browser quota).
- Clear stale text queries when switching to image similarity search.
- Add text-search, image-search, and administration screenshots to the README.
- Simplify administration, group index operations, and collapse diagnostics and privacy details.
- Use only connector-owned Elasticsearch configuration; remove Full Text Search inheritance.
- Give the sidebar toggle a light-blue background and lower the search empty state.
- Gate tagged publication on the complete CI suite and share archive construction across hosts.

## 0.3.6 - 2026-09-10

### Added

- Drag and drop a query image directly onto the search field.
- Frontend regression tests for search controls and image drops, included in CI
  and both release workflows.

### Changed

- Use Media Embedding Service consistently in general documentation, settings,
  translations, and error messages; retain MediaLab External API as the concrete
  supported adapter example.
- Remove the permanent search-page privacy notice; retain the privacy information
  in administration and documentation.

### Fixed

- Show search guidance as an input placeholder instead of a floating label.
- Reserve space for the navigation toggle and align the search controls.

## 0.3.5 - 2026-09-08

### Added

- Visible search-page notice for transmission of search text and temporary query
  images to the administrator-configured Media Embedding Service.
- Psalm level 3 analysis against the official Nextcloud OCP API.
- German, French, and Italian App Store long descriptions.

### Changed

- Added public author and security-contact metadata for App Store publication.
- Pinned CI actions to immutable commit SHAs and added commit-pinned App Store
  metadata schema validation.

### Fixed

- Corrected stale installation, dependency, and uninstall documentation.
- Replaced completed development plans with a concise architecture reference.
- Shortened administration help text in all supported languages.
- Preserved prepared public release checkouts after the publication script exits.
- Clamped retry backoff indexing to a valid array offset.

## 0.3.4 - 2026-09-03

### Added

- Uninstall repair step that removes queued jobs, app configuration, credentials,
  and local database tables while deliberately retaining external Elasticsearch
  indices and aliases.

### Changed

- Rebuilt the admin settings and the search interface with the official
  Nextcloud Vue components.
- Reworded the admin settings to consistently name the configured embedding
  service.
- Resynchronised the German, French, and Italian translation files.
- Replaced the vendor name in the documentation and the app description with
  the neutral "Media Embedding Service" wording.

### Fixed

- Excluded the internal project documentation from the release archive. The
  packaged app no longer ships `docs/`, and the archive validation fails if it
  reappears.

## 0.3.3 - 2026-08-14

### Changed

- Renamed the app ID from `medialab_connector` to
  `media_embedding_connector` and the display name to Media Embedding
  Connector.
- Moved the canonical public repository and release preparation to
  `github.com/Digitale-Medien-der-Armee-DMA/media_embedding_connector`.
- Renamed the PHP namespace, translation domain, database tables, managed
  Elasticsearch indices, frontend identifiers, and release archive.

### Breaking changes

- This release has no upgrade path from the former test-only app ID. Disable
  and remove the old app, remove its test data and managed indices, and install
  this release as a new app.

## 0.3.1 - 2026-08-13

### Added

- Native Nextcloud image preview with keyboard navigation, download, and source
  folder actions.
- Responsive justified image layout with compact relevance information.
- Similarity search using a temporary image selected or dropped in the browser.
- Server-side upload, image-contract, and embedding-vector validation.

### Security

- Updated audited frontend dependencies.

## 0.3.0 - 2026-07-27

### Changed

- Added compatibility support for Nextcloud 33 and 34.

## 0.2.1

### Fixed

- Preserved file changes received while an indexing job is running.
- Prevented terminal, missing, and running jobs from being scheduled again.
- Honored retry times and delayed jobs when worker capacity is exhausted.

Earlier private-development releases are documented in the repository history.
