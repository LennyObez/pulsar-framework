//! Smoke test per plan Section XVII.19 testing convention.
//!
//! Verifies the crate loads and exposes the workspace-version constant.
//! First-run sanity: if this fails, nothing further in the crate works.

#[test]
fn version_is_non_empty() {
    assert!(
        !pulsar_mcp_server::VERSION.is_empty(),
        "VERSION must be a non-empty const"
    );
}

#[test]
fn version_matches_cargo_pkg_version() {
    assert_eq!(pulsar_mcp_server::VERSION, env!("CARGO_PKG_VERSION"));
}
