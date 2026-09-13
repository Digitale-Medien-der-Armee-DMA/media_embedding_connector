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
## Elasticsearch connection ownership

The connector uses only its own Elasticsearch URL and credentials. Full Text
Search backend inheritance is no longer supported. When upgrading an installation
that previously selected the inherited backend, enter and test the Elasticsearch
connection in the connector settings before resuming indexing. Existing custom
connection credentials remain stored; blank credential fields preserve them.
