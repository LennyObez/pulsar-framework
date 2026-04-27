//! Smoke test per plan Section XVII.19 testing convention.
//!
//! First-run sanity for the placeholder release. Verifies the crate loads,
//! exposes the workspace-version constant, and that the standard module
//! skeleton (error::Result alias + prelude module) is importable. If any
//! of these fail, nothing further in the crate works.

use pulsar_csrf::error::{Error, Result};

#[test]
fn version_is_non_empty() {
    assert!(!pulsar_csrf::VERSION.is_empty(), "VERSION must be a non-empty const");
}

#[test]
fn version_matches_cargo_pkg_version() {
    assert_eq!(pulsar_csrf::VERSION, env!("CARGO_PKG_VERSION"));
}

#[test]
fn result_alias_resolves() {
    fn _signature() -> Result<()> { Err(Error::InvalidToken) }
    let _ = _signature();
}

#[test]
fn prelude_module_importable() {
    // If the glob import compiles, the prelude module exists with a public surface.
    #[allow(unused_imports)]
    use pulsar_csrf::prelude::*;
}
