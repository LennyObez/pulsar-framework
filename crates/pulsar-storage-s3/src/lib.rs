//! S3-compatible object storage backend for pulsar-storage — works with AWS S3, MinIO, Backblaze B2, Wasabi.
//!
//! v2.3 sqlx-pattern driver crate per Decision 2.51. Each backend driver
//! lives in its own crate so its CVE history, semver discipline, and
//! audit trail are independent of sibling drivers.
//!
//! See `docs/plan.md` Section IV.21.1 + Section V.3E.X.
//!
//! Placeholder release for namespace reservation. The implementation ships in `0.1.0`.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
