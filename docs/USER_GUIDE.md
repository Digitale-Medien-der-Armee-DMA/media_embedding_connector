# User guide

Media Embedding Connector adds semantic image search to Nextcloud. You can describe an
image in natural language, start a similarity search from an indexed image, or
temporarily provide an image from your device as the search example.

Search text and query images are sent to the Media Embedding Service configured by
your Nextcloud administrator. Server-side temporary uploads are discarded after
the embedding request and are not added to Nextcloud Files or Elasticsearch.
The browser tab remembers query images up to 3 MiB, subject to available session
storage. Returning from Files restores the image and reruns the search. Starting
a text search or clearing the image context removes the remembered image.

## Open the search

Select **Media Search** in the Nextcloud app navigation. Access may be restricted to
specific groups by your Nextcloud administrator.

## Search with text

Enter a description such as `snow-covered mountains at sunset` and submit it.
Results are ordered by semantic similarity. The percentage shown on hover or
through the information control is a relevance score, not a statement of
certainty about the image content.

## Search with an image

Select the image button beside the search field, or drop exactly one supported
image onto the results area. The same data-processing rules above apply to selected and dropped images.

## Work with results

- Select an image to open the Nextcloud preview without leaving search.
- Use the arrow keys in the preview to move through results.
- Download the visible file from the preview.
- Use the folder action to open the source in Files.
- Start another similarity search from an indexed result.

All returned files are checked against your current Nextcloud permissions. A
search result does not grant access to a file.

## Supported formats

JPEG, PNG, and WebP are supported by default. GIF may be enabled when the
configured media embedding model supports it. The administrator can restrict
formats further.

## Troubleshooting

If search is unavailable or incomplete, indexing may not yet be enabled or the
initial backfill may still be running. Contact your Nextcloud administrator.
Include the time of the search and the visible error message, but do not send
private images or credentials in a support request.
