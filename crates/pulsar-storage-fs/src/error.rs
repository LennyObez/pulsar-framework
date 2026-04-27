//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Filesystem I/O failed (permission, disk full, broken symlink).
    #[error("filesystem I/O failed: {0}")]
    Io(String),

    /// Atomic-write rename failed (target file is locked or directory is read-only).
    #[error("filesystem atomic-write rename failed: {0}")]
    AtomicWrite(String),

    /// Sidecar checksum mismatch on read.
    #[error("filesystem checksum mismatch (expected {expected}, computed {computed})")]
    ChecksumMismatch { expected: String, computed: String },

    #[doc(hidden)]
    #[error("placeholder smoke variant")]
    __PlaceholderSmokeOnly,
}

impl Error {
    #[doc(hidden)]
    pub(crate) fn __placeholder_smoke_only() -> Self { Self::__PlaceholderSmokeOnly }
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
