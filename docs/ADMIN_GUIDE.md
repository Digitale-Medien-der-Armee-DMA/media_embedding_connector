# Administrator guide

Media Embedding Connector separates file ownership from embedding and vector search:

- Nextcloud remains authoritative for files, permissions, previews, and result
  visibility.
- The Media Embedding Service receives supported image bytes or text queries
  and returns vectors.
- Elasticsearch stores vectors and technical file references in an index owned
  by the connector.

## Requirements

- Nextcloud 33, 34, or 35; Nextcloud 32 is no longer supported from app 0.4.3
- PHP 8.2 through 8.5; Nextcloud 35 requires PHP 8.3 through 8.5
- Elasticsearch 8.12 or newer
- a compatible Media Embedding Service API under `/api/external/v1`
- Nextcloud cron for background jobs

## Initial configuration

### Service compatibility

The adapter supports the MediaLab External API under `/api/external/v1`,
with model discovery, health checks, text and image embeddings, and optional
image batches. The service must provide a compatible model contract with input
limits, supported formats, vector dimensions, and a model fingerprint. Search
requires normalized vectors and cosine similarity. A generic embedding API is
not automatically compatible.

### Setup

1. Install and enable the app without enabling indexing.
2. Open **Administration settings → Media Embeddings**.
3. Enter the Media Embedding Service endpoint and token.
4. Keep private-network access disabled unless the configured services are
   intentionally hosted on a private network.
5. Test the Media Embedding Service and inspect the returned model contract.
6. Configure and test Elasticsearch.
7. Save the configuration.
8. Prepare the vector index.
9. Enable indexing.
10. Start backfill to index existing images and inspect failed jobs.

Installing or enabling the app does not start indexing.

## Production operation

Use system cron rather than AJAX background jobs. Monitor queued, running,
failed, skipped, and indexed items from the administration page. Retry temporary
failures only after the underlying service has recovered.

Click **Queued jobs**, **Running jobs**, **Failed jobs**, or **Skipped files**
under **Operational status** to download a JSON file containing the corresponding
records. Downloads require administrator access. They include file IDs, names,
numeric storage IDs and storage-relative paths, plus queue details or skip reasons.
Missing file-cache entries retain their records with null file metadata. Skipped
files are exported from persistent skip markers, matching the displayed counter;
the other downloads use job records. There is no fixed record limit. Export
generation uses paginated database reads and a temporary file, which is deleted
automatically. Job statuses can change during generation, so the exported count
may differ from a previously refreshed status card.

### Search coverage, quality and sessions

Every new search enumerates all supported, readable image IDs through the user's
Nextcloud filesystem, including received shares and mounted group/external folders.
Candidate IDs are read in keyset pages from the file-cache roots of the current
mounts, then checked through Nextcloud's permission-aware filesystem search with
`fileid IN (...)`. Database candidates alone never grant access. This avoids the
unsupported `fileid > ...` filesystem comparison and deep offset pagination.
There is no total scope cap. Enumeration is live for each new query so unobserved
third-party ACL or mount changes cannot leave a shared permission cache stale.
The IDs form an Elasticsearch **pre-filter**; ranking never starts with global
hits that are filtered afterwards. Up to 50,000 IDs are sent per scope block and
all blocks contribute to the merged ranking.

A complete scope of at most 10,000 image IDs uses exact cosine vector scoring.
Larger scopes use approximate kNN with `num_candidates=10000` per shard per block.
The exact threshold is a starting value, not a benchmark for a particular server.
Both methods require normalized cosine vectors and filter by the search index's
model fingerprint. ANN does not guarantee exact nearest neighbours. Only suitable
images already indexed under that model can be found; skipped, failed and missing
images need indexing first.

The actual search alias target and its model are resolved before embedding. A
short-lived Elasticsearch point in time keeps all scope-block searches consistent.
The merged ranking retains at most 500 results to bound retrieval cost, storage and
permission checks. The default page shows 49 images, including the reference for
similar-image search. When the pool is exhausted and more candidates existed, the
UI asks the user to refine the query. The result cap never limits the file scope
searched. New indices explicitly use unquantized `hnsw`; existing vector mappings
are not changed by this update.

Ranked IDs and scores are stored as an owner-bound, random-token search session in
Nextcloud for 15 minutes. Query text, uploaded images, query vectors and the full
permission list are not stored in these sessions. Subsequent pages reuse the
ranking without re-embedding, re-uploading, filesystem enumeration or ES searches.
Each page checks current Nextcloud read permissions, skips deleted/revoked files,
and fills from later candidates without shifting earlier pages. Technical lookup
errors fail explicitly. New grants and newly indexed files appear in a **new**
search; the current session keeps its original ordering. Expired sessions require
a new search. Failed paging stops automatic retries and offers a manual retry.

New index mappings contain the model identity in `_meta.embedding_contract`.
Existing indices fall back to the model fields on an indexed document, so no
re-embedding is required for this change. An empty legacy index with no model
metadata cannot be searched until its model identity is established. The MediaLab
adapter currently uses default-model inference: if its default model differs from
the search index, text/upload searches stop with an explicit mismatch instead of
returning incompatible results. Existing sessions and indexed-image similarity
search do not need MediaLab inference. Model fingerprints are checked again on
embedding responses to detect changes during a request.

Version 0.4.3 adds `media_embed_search` through the app's database migration.
Expired rows are cleaned by a five-minute Nextcloud background job and sessions are
removed when the app is uninstalled. Upgrade the app and run its normal Nextcloud
migration before using the new frontend. First-search latency still includes full
live scope enumeration: measure on the production dataset, particularly for users
with access to hundreds of thousands of images. Cached pages avoid that work.

### How indexing runs

- New and changed images are written to the connector queue only. They do not
  create a Nextcloud background job per file.
- The image worker job claims batches from that queue and keeps processing for
  up to `worker_time_budget` seconds per cron run (default 240). The number of
  worker jobs that may run in parallel follows **Maximum parallel batch
  requests per token**.
- The backfill scan job reads the file cache page by page and saves its
  position after every page. It adds images to the queue only while fewer than
  `backfill_max_queued` backfill images are waiting (default 10000).
- The scan covers each user's home folder and mounted group folders and
  external storages. Received shares are scanned through their owner. Files
  must already be in the Nextcloud file cache; run `occ files:scan` for
  external storages that were never scanned.
- Images whose stored index entry or skip marker still matches their ETag, the
  active model fingerprint, and the write index are not queued or sent again.
- Indexed and skipped job rows are deleted after `job_retention_days` (default
  14). Failed jobs are kept for inspection and retry.

The three values can be changed with `occ config:app:set
media_embedding_connector <key> --value=<number> --type=integer`.

### Pausing, resuming, and restarting a backfill

**Pause backfill** stops the scan at its last saved position; already queued
images continue to be processed. **Resume backfill** continues from that
position. **Start backfill** keeps the position of a running scan. To scan all
users again from the beginning, send `restart=1` with the start request;
unchanged images are still skipped.

Under **Existing images**, the administration page shows the scan state
(scanning, waiting for the queue, paused, completed), scanned users, checked,
queued, and unchanged images, and the number of backfill images waiting in the
queue. It warns when a running scan has not progressed for 15 minutes, which
usually means that neither cron nor the worker command is running, and shows
the last scan error and any scan locations skipped after repeated failures.
The page refreshes this status every 30 seconds while work is pending. Scan
errors are logged with the underlying exception.

### Continuous worker for large collections

Cron processes the queue only when a cron run starts. For collections with
millions of images, run the continuous worker next to cron:

```sh
sudo -u www-data php occ media_embedding_connector:worker --time-limit=3600
```

The worker processes batches back to back, advances the backfill scan when the
queue needs more work, and waits `--idle-sleep` seconds (default 5) only when
nothing is ready. It exits after `--time-limit` seconds so a supervisor can
start a fresh process; use `--no-scan` to leave scanning to cron, for example
when more than one worker process runs. Cron jobs
keep running as a fallback; concurrent workers never claim the same image.

Example systemd unit:

```ini
[Unit]
Description=Media Embedding Connector worker
After=network-online.target

[Service]
User=www-data
ExecStart=/usr/bin/php /var/www/nextcloud/occ media_embedding_connector:worker --time-limit=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

#### Complete systemd setup

A more complete unit, saved as
`/etc/systemd/system/media-embedding-worker.service`:

```ini
[Unit]
Description=Media Embedding Connector worker
After=network-online.target mariadb.service mysql.service postgresql.service redis.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/nextcloud
ExecStart=/usr/bin/php occ media_embedding_connector:worker --time-limit=3600 -v
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Adjust `User` to the account that runs Nextcloud (for example `www-data`,
`nginx`, or `apache`), the PHP and Nextcloud paths, and the services listed in
`After=`. systemd ignores listed services that do not exist.

Enable, start, and monitor the worker:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now media-embedding-worker
systemctl status media-embedding-worker
journalctl -u media-embedding-worker -f
```

With `-v`, the worker writes a journal line whenever it has processed images.
Errors are written to the Nextcloud log as usual.

- The worker exits every hour because of `--time-limit=3600`, and
  `Restart=always` starts a fresh process five seconds later. This releases
  memory and loads new app code after an upgrade. `--time-limit=0` disables
  the limit.
- Stopping the worker ends the process immediately, including a batch that is
  in progress. The images claimed by that batch return to the queue after 30
  minutes and are processed again.
- Stop the worker before upgrading the app and start it again afterwards:
  `sudo systemctl stop media-embedding-worker`.
- If Nextcloud runs in a container, run the command inside it, for example
  `ExecStart=/usr/bin/docker exec -u www-data <container> php occ media_embedding_connector:worker --time-limit=3600 -v`.
- To run several workers, use a template unit such as
  `media-embedding-worker@.service` and start `@1`, `@2`, and so on. Add
  `--no-scan` to all instances except one so that only one process advances
  the backfill scan. One worker next to cron is usually sufficient.

Increase batch size and parallel requests only after measuring the throughput
and error rate of the Media Embedding Service.

When the Media Embedding Service model fingerprint changes, prepare a new index
and complete its backfill before activating it. The previous index remains
available for rollback until an administrator deletes it.

## Data protection

Before activation, document who operates the Media Embedding Service and
Elasticsearch, where they are hosted, which retention and logging rules apply,
and whether transmitting image content is permitted for the affected users and
data classifications. See [PRIVACY.md](../PRIVACY.md) for the technical data
flow.

## Uninstalling

Uninstalling the app removes its queued jobs, stored configuration, credentials,
and local database tables. External Elasticsearch indices and aliases are
deliberately retained. Delete them manually only after confirming that rollback
or reuse is no longer required.
