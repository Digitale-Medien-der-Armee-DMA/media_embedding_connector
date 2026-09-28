# Changelog

## Unreleased

## 0.4.2 - 2026-09-28

- Keep search pagination usable in large browser windows by rechecking the
  infinite-scroll sentinel after each request and always showing a manual
  **Load more** fallback.
- Allow search pagination beyond the first 500 vector candidates and use a
  fixed baseline of 2,000 Elasticsearch candidates for consistent result
  quality while preserving result overfetch for Nextcloud permission checks.

## 0.4.1 - 2026-09-28

- Republish the 0.4.0 changes as a new release. No functional changes.

## 0.4.0 - 2026-09-28

- Process queued images with a time-budgeted image worker instead of adding a
  Nextcloud background job for every file. This prevents the batch job from
  being postponed continuously while a backfill scan is running.
- Add `occ media_embedding_connector:worker` for continuous processing outside
  the cron interval.
- Replace per-folder scan jobs with a file-cache scan that saves its position,
  resumes after pausing, and stops adding work while the backfill queue is
  full.
- Skip images whose index entry or skip marker still matches their content and
  the active model, both when scanning and before sending an image.
- Queue and claim jobs without locking the job table.
- Delete indexed and skipped job rows after a retention period.
- Log backfill scan failures with their exception.
- Show backfill scan progress, queue throttling, stalled scans, and the last
  scan error in the administration settings.

## 0.3.7 - 2026-09-13

- Restore image searches when returning from Files, with query images up to
  3 MiB remembered in the browser tab, subject to available storage.
- Clear previous text queries when switching to image similarity search.
- Simplify administration with grouped index operations and collapsible
  diagnostics.
- Improve search controls, sidebar styling, and empty-state positioning.
- Add text-search and image-search screenshots to the documentation.

## 0.3.6 - 2026-09-10

- Support dropping a query image directly onto the search field.
- Improve search-field guidance and control alignment.
- Use consistent Media Embedding Service terminology in settings and messages.
