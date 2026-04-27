//! Subresource Integrity (SRI) digest enforcement for inline + external assets.
//!
//! Implements the W3C SRI Recommendation:
//!
//! * `sha256` / `sha384` / `sha512` digest emission on rendered `<script>`,
//!   `<link>`, `<img>`, `<audio>`, `<video>` tags via `pulsar-engine`.
//! * Verification of pre-computed manifests at runtime for inlined or
//!   proxied assets.
//! * Compatibility with cross-origin asset loading + the `crossorigin`
//!   attribute SRI requires.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.11.2 (per-crate spec) and Section V.1.5
//! (implementing sprint). Creusot contract on `verify_digest` per Decision 2.20.
//!
//! Placeholder release for namespace reservation. The implementation ships in `0.1.0`.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
