# Privacy and data processing

This document describes the connector's technical data flow. It is not a legal
privacy notice and does not replace an assessment by the responsible operator.

## Roles and deployment

The Nextcloud administrator chooses and operates, or contracts, the media
embedding and Elasticsearch services. The connector does not prescribe whether
those services run on the same infrastructure, on a private network, or at a
third party. Operators must document the actual deployment and applicable legal
basis.

## Data sent to the Media Embedding Service

For indexing and image-based similarity search, the Media Embedding Service
receives supported image bytes, individually or in batches. For text search, it receives the
submitted search text. Each request also contains a random request identifier.

The connector does not intentionally send stable Nextcloud file IDs, filenames,
paths, owners, users, groups, shares, or access-control lists to the media
Media Embedding Service. The configured service and intervening network infrastructure
can still process ordinary connection metadata such as IP addresses, timing, and
request size.

Images are sent without stripping EXIF, IPTC, or XMP metadata. Embedded location,
author, or other identifying information may therefore reach the service.

Temporary browser query images are handled as PHP uploads, sent to the media
Media Embedding Service for embedding, and discarded after the request. They are not
inserted into Nextcloud Files or Elasticsearch.

## Data stored in Elasticsearch

The connector-managed index contains vectors and technical references required
to resolve an indexed file, including a Nextcloud file identifier, file state,
MIME type, size, model information, numeric storage/folder ancestry identifiers,
a structure schema version, and operational timestamps. It does not
intentionally store filenames, paths, owners, users, groups, shares, or ACLs.

Version 0.4.4 removes legacy string storage IDs (which could encode a user ID)
from the active indices during its metadata pass. Until that pass finishes,
legacy documents can still contain those values. Numeric folder references
describe structure, not user/group ACLs; a user's current readable roots are
resolved live for each new search and used only in its ES request.

Search candidates are resolved through the current user's Nextcloud file tree.
Files that are deleted, inaccessible, or no longer shared are excluded before
results are returned.

## Data stored in Nextcloud

The app stores indexing state, jobs, skip markers, model/index state, audit
events, administrator configuration, persistent structure-repair tasks, and
metadata error records with storage-relative paths. Short-lived search sessions store the
user ID, index/model identity and up to 500 ranked file IDs with scores. They do
not store query text, uploaded query images, query vectors or full ACL lists.
Sessions expire after 15 minutes; expired rows are removed by a five-minute
Nextcloud background job when cron runs. Service credentials are never returned
to the browser after storage.

## Data stored in the browser

Recent text searches are stored in localStorage on the Nextcloud origin until
the user clears the search history or browser site data. The active text or
similar-image search is stored in sessionStorage for reloads. Uploaded query
images up to 3 MiB are also stored in the tab's sessionStorage when browser quota
allows it. Returning to the search app restores the image and submits the search
again. Switching to text search or clearing the image context removes the stored
image. Larger images remain in memory only. Session data normally ends with the
tab session; browser session restoration may preserve it.

## Operator checklist

Before enabling indexing, define and communicate:

- the operators and locations of the Media Embedding Service and
  Elasticsearch;
- the legal basis and permitted data classifications;
- service-side logging and retention;
- technical and organizational access controls;
- deletion, incident-response, and data-subject-request processes;
- whether users may submit images from outside Nextcloud as temporary queries.
