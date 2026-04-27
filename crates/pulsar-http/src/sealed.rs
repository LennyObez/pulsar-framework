//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! Placeholder pending the per-sprint public-trait surface landing. Public
//! traits that downstream code must not implement gain a `Sealed` super-
//! trait declared here so `impl` blocks outside this crate fail to compile.
