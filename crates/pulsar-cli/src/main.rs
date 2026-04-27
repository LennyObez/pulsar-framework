//! Pulsar Framework CLI binary entry point.
//!
//! Wraps the `pulsar_cli` library crate so that `cargo install pulsar-cli`
//! produces a `pulsar` binary on the user's PATH. Per plan Section IV crate
//! 4.52, the implementation lands at Sprint 4.2 with the full subcommand
//! catalogue (new, migrate, seed, routes, serve, extension install/remove/
//! list, audit verify, diagnostics, key generate, key rotate, dsar export,
//! consent report, import, export, doctor, lint).

use std::process::ExitCode;

fn main() -> ExitCode {
    eprintln!(
        "pulsar-cli v{} — placeholder release for namespace reservation. \
         The implementation ships at Sprint 4.2 per docs/plan.md Section V.",
        pulsar_cli::VERSION
    );
    ExitCode::SUCCESS
}
