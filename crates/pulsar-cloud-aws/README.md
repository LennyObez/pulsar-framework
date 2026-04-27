# pulsar-cloud-aws

AWS adapter for pulsar-cloud — S3, Lambda, SES, KMS, IAM, SecretsManager.

## What

Implements the `pulsar-cloud` adapter trait set against AWS via the official Rust SDK (`aws-sdk-*`). Surfaces: S3 for storage, KMS for envelope encryption, SecretsManager + Parameter Store for configuration, IAM role assumption for cross-account, Lambda invoke for event-driven hooks, SES for transactional mail.

## Why

AWS is the largest single cloud provider in regulated workloads. Splitting per Decision 2.51 means an aws-sdk-s3 CVE does not taint downstream applications running on Azure or GCP. The composition root selects exactly one cloud adapter at build time.

## How

`pulsar-cloud-aws` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Default credential resolution chain (instance profile → environment → SSO → static keys) preserved from the AWS SDK.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.48.1 + Section V.3E.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
