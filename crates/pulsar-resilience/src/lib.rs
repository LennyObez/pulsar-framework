//! Circuit breaker + bulkhead + retry + timeout primitives.
//!
//! Composable `tower::Layer` primitives for failure-domain isolation,
//! adapted from the Hystrix (Netflix, 2012) and resilience4j (2018)
//! pattern catalogues. State is in-memory single-instance; multi-instance
//! coordination is out of scope (use the service mesh's circuit-breaker
//! for that).
//!
//! Composition pattern:
//!
//! ```text
//! tower::ServiceBuilder::new()
//!     .layer(circuit_breaker)
//!     .layer(bulkhead)
//!     .layer(retry)
//!     .layer(timeout)
//!     .service(downstream)
//! ```
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.11.5 (per-crate spec) and Section V.1.5.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
