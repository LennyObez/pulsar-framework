//! Workspace-local build automation per the [cargo-xtask] pattern.
//!
//! Invoked from the workspace root via `cargo xtask <subcommand>`. Subcommands
//! that already work as `cargo` aliases (per `.cargo/config.toml`) are not
//! re-implemented here; xtask hosts the workflows that need orchestration
//! across multiple cargo invocations or external tools.
//!
//! [cargo-xtask]: https://github.com/matklad/cargo-xtask

#![forbid(unsafe_code)]

use std::process::ExitCode;

use anyhow::{Context, Result};
use clap::{Parser, Subcommand};

#[derive(Parser)]
#[command(
    name = "xtask",
    about = "Pulsar Framework workspace-local build automation",
    version
)]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Regenerate `docs/adr/INDEX.md` from the ADR files in `docs/adr/`.
    AdrIndex,
    /// Regenerate `docs/api-surface.md` from the public surface of the workspace.
    ApiSurface,
    /// Run the full Section VI quality-gate sequence in order.
    QualityGate,
    /// Verify reproducible-build determinism (Section 14.6) by comparing two builds.
    ReproBuild,
    /// Generate the dependency-ordered crate list for `publish.yml` ORDER array.
    PublishOrder,
}

fn main() -> ExitCode {
    let cli = Cli::parse();
    match dispatch(cli.command) {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("xtask: {e:?}");
            ExitCode::FAILURE
        }
    }
}

fn dispatch(cmd: Command) -> Result<()> {
    match cmd {
        Command::AdrIndex => adr_index(),
        Command::ApiSurface => api_surface(),
        Command::QualityGate => quality_gate(),
        Command::ReproBuild => repro_build(),
        Command::PublishOrder => publish_order(),
    }
}

/// Placeholder for ADR index regeneration. Implementation arrives at the
/// sprint identified in `docs/plan.md` Section V Sprint 0.9 Hardening.
fn adr_index() -> Result<()> {
    anyhow::bail!(
        "ADR index regeneration not yet implemented; manually maintain docs/adr/INDEX.md \
         per ADR-0008 sprint-feature-branch contract until the implementation lands"
    )
}

/// Placeholder for API surface regeneration.
fn api_surface() -> Result<()> {
    anyhow::bail!(
        "API surface regeneration not yet implemented; the cargo doc + cargo public-api \
         pipeline lands at Sprint 4.5 documentation consolidation"
    )
}

/// Placeholder for the full Section VI quality-gate orchestration.
fn quality_gate() -> Result<()> {
    anyhow::bail!(
        "Quality-gate orchestration not yet implemented; run the sequence in CLAUDE.md \
         Section 3 manually until Sprint 0.4+ extension lands xtask wiring"
    )
}

/// Placeholder for reproducible-build verification (Section 14.6 SLSA Level 4).
fn repro_build() -> Result<()> {
    anyhow::bail!(
        "Reproducible-build verifier not yet implemented; landing at Sprint 4.4 exhaustive \
         tests + Sprint 4.6 audit per plan Section 14.6"
    )
}

/// Placeholder for publish-order topological sort across the 53-crate workspace.
fn publish_order() -> Result<()> {
    let manifest = workspace_root_manifest_path()?;
    println!("Workspace root manifest at {}", manifest.display());
    anyhow::bail!(
        "Topological sort + ORDER array generation not yet implemented; \
         hand-curated ORDER list in .github/workflows/publish.yml is authoritative \
         until this lands"
    )
}

fn workspace_root_manifest_path() -> Result<std::path::PathBuf> {
    let mut current = std::env::current_dir().context("read current directory")?;
    loop {
        let candidate = current.join("Cargo.toml");
        if candidate.exists() {
            // Heuristic: the workspace root is the deepest Cargo.toml that contains [workspace]
            let txt = std::fs::read_to_string(&candidate)
                .with_context(|| format!("read {}", candidate.display()))?;
            if txt.contains("[workspace]") {
                return Ok(candidate);
            }
        }
        let Some(parent) = current.parent() else {
            anyhow::bail!("no [workspace] Cargo.toml found in any ancestor directory");
        };
        current = parent.to_path_buf();
    }
}
