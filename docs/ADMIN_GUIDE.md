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
records. Downloads require administrator access. Each download is a JSON array
with only `id`, `last_error`, and `storage_path`. `id` is the job or skip-marker
record ID, not the Nextcloud file ID. For skipped files, `last_error` contains the
skip reason. Paths are storage-relative. Missing file-cache entries retain their
records with a null path; a deleted file's former path cannot be reconstructed
from that entry. Skipped
files are exported from persistent skip markers, matching the displayed counter;
the other downloads use job records. There is no fixed record limit. Export
generation uses paginated database reads and a temporary file, which is deleted
automatically. Job statuses can change during generation, so the number of entries
may differ from a previously refreshed status card.

### ScopeSearch: search coverage, quality and sessions

Version 0.4.4 uses **ScopeSearch**. Each new search resolves the user's own Files
root and the actual, readable roots of received mounts. Shared folders become
ancestor-ID filters; individually shared files become explicit file-ID filters.
It does not list the images or walk the subfolders before searching. Multiple
group memberships and overlapping share routes form a union and produce one hit
per file. A shared subfolder never grants its parent's entire storage.

ScopeSearch supports standard Nextcloud user/group shares and inherited readable
folder permissions on local storage, including NFS-backed local storage and
Groupfolders without advanced ACLs. External mounts and detected advanced
Groupfolders ACL wrappers fail explicitly; they require a separate permission
adapter. Technical mount failures abort the search rather than silently omit a
region. Nextcloud remains the authority for access; no per-user photo ACL list is
stored in Elasticsearch.

Elasticsearch receives this permission filter **before** vector ranking. A
bounded count at the same point in time selects exact cosine scoring when at most
10,000 indexed, model-compatible documents match. Larger scopes use approximate
kNN with `num_candidates=10000` per shard. Every eligible indexed image in the
allowed scope participates; ANN does not guarantee mathematically exact nearest
neighbours. New vector mappings use unquantized `hnsw`; existing vectors and their
mapping are preserved. Skipped, failed, unindexed, or model-incompatible images
still require normal indexing.

The ranking retains at most 500 results, with 49 shown on the default page. A
15-minute, owner-bound session stores only IDs, scores and technical search
context. Further pages reuse the ranking. Each returned image is resolved through
the user's current filesystem and checked for read access; deleted/revoked files
are replaced from later candidates. Names and visible paths are obtained only
from Nextcloud. New grants become available on a new search. A changed MediaLab
model is rejected if it does not match the actual search alias target.

### Updating the structure metadata (schema 1 → 2)

The normal 0.4.4 app upgrade creates `media_embed_structure` and
`media_embed_struct_err`. The first cron or continuous-worker slice starts the
metadata upgrade automatically once an index is prepared. It adds
`structure_schema`, `storage_numeric_id`, and `ancestor_ids` to the existing
managed indices. It scans the actual search and write index documents in keyset
batches, including documents absent from the connector's tracking table, and
updates structure from Nextcloud's filecache using indexed file IDs and
`storage + path_hash` lookups for ancestors. Per-file locks prevent concurrent embedding writes from being overwritten by
stale metadata updates. It never reads image bytes or calls
the embedding service. Legacy string `storage_id` values are removed from ES
because they can contain a user identifier. No new vector index or re-embedding
is required; unchanged metadata is a no-op on subsequent passes. Existing index names and freshness records remain valid.

Administration shows three lines: the schema update, its status, and
`Processed: done / total`. **Download errors**, **Restart**, **Pause**, and
**Resume** control the same persistent operation. Control requests are stored independently of worker progress and remain
responsive during a running batch. Pause and Restart take effect between
batches; Resume preserves the cursor. Restart (also Resume after a failed full
run) starts a fresh metadata pass and retains vectors. Error downloads contain
only `id` (Nextcloud file ID; 0 denotes a run-wide error), `last_error`, and
`storage_path`. Paths are storage-relative and can be null if a file was already
removed. Totals count ES documents across distinct search/write indices and may
differ from the current file count if files change during the pass.

Initial search waits until the metadata pass succeeds. Ordinary file indexing
writes schema-2 structure immediately. Moves and deletions enqueue persistent,
targeted repairs for the affected file/tree; deleting into the trash removes its
vectors explicitly. During a repair, only searches overlapping its old or new
scope wait, while unrelated users can continue searching. Failed repairs remain
queued and expose errors instead of quietly serving incomplete affected scopes.
The metadata worker runs independently of the embedding/backfill enable switch;
use the dedicated metadata Pause button to pause it. A manual Restart also
reconciles structure after filecache changes outside normal Nextcloud events.

Cron advances the run for a bounded slice once a minute. For large inventories,
use the continuous worker below to avoid cron gaps. A running ES request can
outlive a slice's time budget by its request timeout. Benchmark real mount
resolution, ranking, and live result checks on your deployment; a million-image
load or a particular latency is not certified by functional contract tests.

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
