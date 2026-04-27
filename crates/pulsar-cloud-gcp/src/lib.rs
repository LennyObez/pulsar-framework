//! GCP adapter for pulsar-cloud — Cloud Storage, Cloud KMS, Cloud Functions, SecretManager.
//!
//! v2.3 sqlx-pattern driver crate per Decision 2.51. Each backend driver
//! lives in its own crate so its CVE history, semver discipline, and
//! audit trail are independent of sibling drivers.
//!
//! See `docs/plan.md` Section IV.48.3 + Section V.3E.2.
//!
//! Placeholder release for namespace reservation. The implementation ships in `0.1.0`.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
