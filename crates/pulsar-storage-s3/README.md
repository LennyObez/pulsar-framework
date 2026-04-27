# pulsar-storage-s3

S3-compatible object storage backend for pulsar-storage — works with AWS S3, MinIO, Backblaze B2, Wasabi.

## What

Implements the `pulsar-storage` backend trait set against any S3-compatible object store via `aws-sdk-s3`: AWS S3, MinIO, Backblaze B2, Wasabi, Cloudflare R2, OVH Cloud, Scaleway. Supports multipart upload, server-side encryption (SSE-S3, SSE-KMS, SSE-C), pre-signed URLs, lifecycle policies (object expiry, archival to Glacier-equivalent).

## Why

Object storage is the canonical primitive for blob-shaped data: avatars, attachments, audit-log archives, backup snapshots. S3 + S3-compatible together cover ≥ 90 % of regulated-domain object-storage deployments. Splitting per Decision 2.51 means a S3-protocol CVE does not taint Azure-Blob or GCS-Storage code paths.

## How

`pulsar-storage-s3` is a v2.3 sqlx-pattern driver crate per Decision 2.51. The `AWS_ENDPOINT_URL` environment variable redirects the SDK to a non-AWS S3-compatible endpoint.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.21.1 + Section V.3E.X.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
