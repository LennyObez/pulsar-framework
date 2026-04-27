//! Server-Side Request Forgery (SSRF) protection.
//!
//! Wraps the outbound HTTP client with a pre-flight URL gate:
//!
//! * Hostname resolution via `hickory-resolver`.
//! * Rejection of IANA-reserved IPs + cloud-metadata IPs
//!   (`169.254.169.254`, `fd00:ec2::254`, `metadata.google.internal`,
//!   `metadata.azure.com`).
//! * DNS-rebinding defence (re-resolve at connect-time, reject on mismatch).
//! * Per-outbound-class hostname allowlists.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.11.6 (per-crate spec) and Section V.1.5.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
