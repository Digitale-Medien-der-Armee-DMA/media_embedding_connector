# Administrator guide

Media Embedding Connector separates file ownership from embedding and vector search:

- Nextcloud remains authoritative for files, permissions, previews, and result
  visibility.
- The Media Embedding Service receives supported image bytes or text queries
  and returns vectors.
- Elasticsearch stores vectors and technical file references in an index owned
  by the connector.

## Requirements

- Nextcloud 32, 33, or 34
- PHP 8.2 through 8.5
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
