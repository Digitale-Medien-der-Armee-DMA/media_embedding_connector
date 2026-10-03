# Changelog

## 0.4.3 - 2026-10-03

- Update all locked brace-expansion copies and DOMPurify to patched releases and
  rebuild the shipped frontend bundles.
- Support Nextcloud 33–35, including API compatibility checks and a Nextcloud 35
  runtime contract test. Drop support for Nextcloud 32. Nextcloud 35 requires PHP
  8.3 or newer; older supported Nextcloud versions can still use PHP 8.2.
- Enumerate mounted file-cache candidates with keyset pagination, then validate
  access through the supported `fileid IN` filesystem filter. Cover own photos,
  received shares and revoked access with an actual Nextcloud runtime test.
- Search the complete readable Nextcloud image scope before ranking. Use exact
  cosine search for scopes up to 10,000 images and ANN with 10,000 candidates per
  shard for larger scopes; merge all scope blocks and display 49 images per page.
- Keep up to 500 ranked results in owner-bound, 15-minute search sessions. Reuse
  the ranking when paging without new embeddings, uploads or scope enumeration;
  recheck current permissions and refill pages after revocations.
- Pin searches to one concrete index/model and use an ES snapshot while building
  the ranking. Reject model drift, partial searches and technical permission
  lookup failures. Create new indices with explicit unquantized HNSW mappings.
- Stop automatic retries after failed paging, show actionable session/model
  errors and abort superseded browser requests.
- Add administrator JSON downloads for queued, running and failed jobs and
  persistent skipped files, including names, storage paths and diagnostic details.

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
