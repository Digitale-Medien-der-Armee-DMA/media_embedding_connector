# Security policy

## Supported versions

Security fixes are provided for the latest released version unless a different
support window is announced in the release notes.

## Reporting a vulnerability

Do not disclose suspected vulnerabilities in a public issue. Report them
privately to [support@dma.swiss](mailto:support@dma.swiss). This monitored
mailbox is the security contact for the application.

Include the affected version, deployment assumptions, reproduction steps, and
impact. Do not include production credentials, private user images, or personal
data.

## Security boundary

The app connects Nextcloud to administrator-configured media embedding and
Elasticsearch services. Administrators are responsible for their availability,
authentication, TLS configuration, network placement, retention, and logging.
The connector performs server-side permission filtering before returning search
results, but it does not make an untrusted embedding or Elasticsearch service
safe to operate.
