# Version 2 investigation notes

Keep the current release deliberately simple. Before scaling deployments to
roughly 500,000 images per tenant, investigate these changes for version 2:

1. Add tenant and ACL fields to indexed documents and apply them as mandatory
   Elasticsearch kNN pre-filters. Keep the Nextcloud permission check as a
   defense-in-depth validation.
2. Define explicit vector index options, including the quantization strategy,
   HNSW `m`, and `ef_construction`, based on measurements with production-like
   embeddings.
3. Evaluate exact rescoring of the strongest approximate kNN candidates, with
   an implementation compatible with the supported Elasticsearch versions.
4. Add a repeatable relevance and performance benchmark comparing approximate
   results with an exact baseline. Track recall, result quality, and p50/p95
   latency for representative tenant data.
5. Replace increasingly deep offset searches with a bounded result set or a
   server-side search session that can be paged without repeatedly requesting
   a larger kNN result window.
