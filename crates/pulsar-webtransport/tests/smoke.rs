//! Smoke test per plan Section XVII.19 testing convention.

use pulsar_webtransport::error::{Error, Result};

#[test]
fn version_is_non_empty() {
    assert!(
        !pulsar_webtransport::VERSION.is_empty(),
        "VERSION must be a non-empty const"
    );
}

#[test]
fn version_matches_cargo_pkg_version() {
    assert_eq!(pulsar_webtransport::VERSION, env!("CARGO_PKG_VERSION"));
}

#[test]
fn result_alias_resolves() {
    fn _signature() -> Result<()> {
        Err(Error::ConnectRejected)
    }
    let _ = _signature();
}

#[test]
fn prelude_module_importable() {
    #[allow(unused_imports)]
    use pulsar_webtransport::prelude::*;
}
