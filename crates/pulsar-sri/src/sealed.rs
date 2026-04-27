//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! Sprint 1.5 implementation will declare `Sealed` for `IntegrityBackend`
//! (asset-pipeline storage adapter) so third-party backends cannot bypass
//! the manifest-verification path.

pub(crate) trait Sealed {}
