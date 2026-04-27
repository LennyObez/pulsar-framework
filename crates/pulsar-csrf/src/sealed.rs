//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! Public traits that downstream code must not implement gain a `Sealed`
//! super-trait declared here so `impl` blocks outside this crate fail to
//! compile. The Sprint 1.5 implementation will declare `Sealed` for the
//! `CsrfBackend` trait (storage adapter for synchroniser tokens) so that
//! third-party adapters cannot bypass the constant-time verification path.

/// Crate-private sealing trait per the standard sealed-trait pattern
/// (RFC 0445). Public traits declare `: sealed::Sealed` in their where
/// clause; downstream `impl` blocks fail because `Sealed` is unreachable.
#[allow(dead_code)]  // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
