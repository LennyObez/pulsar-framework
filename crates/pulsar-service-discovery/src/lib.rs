//! Service discovery + health checking.
//!
//! Resolves logical service names to live network endpoints with health
//! and load-balancing metadata. Adapter set: DNS-SD (RFC 6763 SRV + TXT),
//! Consul (HTTP API), etcd (gRPC), Kubernetes Endpoints (watch API).
//!
//! Stream-based subscription so consumers react to topology changes in
//! real time. Health checks integrate with `pulsar-resilience` for
//! circuit-breaker propagation.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-cluster` meta-crate per
//! Decision 2.51. See `docs/plan.md` Section IV.50.2 + Section V.3E.4.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
