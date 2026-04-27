//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Service discovery + health-check failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Discovery backend (DNS / Consul / etcd / k8s) unreachable.
    #[error("discovery backend unavailable: {backend}")]
    BackendUnavailable { backend: String },

    /// Service name resolved to zero healthy endpoints.
    #[error("no healthy endpoints for service: {service}")]
    NoHealthyEndpoints { service: String },

    /// Health-check probe failed for a known endpoint.
    #[error("health probe failed for endpoint {endpoint}: {reason}")]
    HealthProbeFailed { endpoint: String, reason: String },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
