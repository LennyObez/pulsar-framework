//! Build script for pulsar-crypto-hacl-bindings.
//!
//! Compiles the HACL* C distribution from `hacl-c/` into a static library
//! linked into the binding crate. At Phase 0 the `hacl-c/` directory is a
//! placeholder; Sprint 1.1 (kernel crypto) lands the actual extracted C
//! sources from the upstream HACL* repository (vendored to ensure SLSA
//! Level 4 reproducibility — per Decision 2.57).
//!
//! On the Phase 0 placeholder the build does nothing if `hacl-c/` is empty,
//! emitting a `cargo:rustc-cfg=hacl_placeholder` so the lib.rs compiles
//! without C symbols.

fn main() {
    println!("cargo:rerun-if-changed=hacl-c/");

    // Declare the `hacl_placeholder` cfg so Rust 1.80+ does not emit the
    // `unexpected_cfgs` lint. Required because the workspace lints policy
    // promotes warnings to errors via RUSTFLAGS="-D warnings". Without
    // this declaration `cargo check` would fail at the workspace lint
    // gate even when the cfg is never set.
    println!("cargo::rustc-check-cfg=cfg(hacl_placeholder)");

    // Detect whether HACL* C sources are present.
    let hacl_c_dir = std::path::Path::new("hacl-c");
    let has_sources = hacl_c_dir
        .read_dir()
        .map(|mut iter| iter.any(|e| e.map(|e| e.file_name() != ".gitkeep").unwrap_or(false)))
        .unwrap_or(false);

    if !has_sources {
        // Phase 0 placeholder: HACL* C distribution lands at Sprint 1.1.
        println!("cargo:rustc-cfg=hacl_placeholder");
        println!("cargo:warning=pulsar-crypto-hacl-bindings: hacl-c/ is empty (Phase 0 placeholder); FFI symbols will be unavailable until Sprint 1.1.");
        return;
    }

    // Sprint 1.1 + later: compile the vendored HACL* C distribution.
    let mut build = cc::Build::new();
    build
        .include("hacl-c/include")
        .flag_if_supported("-Wall")
        .flag_if_supported("-Wextra")
        .flag_if_supported("-O3");

    // Sprint 1.1 will enumerate the .c files explicitly (HACL* ships per-primitive
    // .c files under hacl-c/src/) — this stub does not enumerate to keep the
    // placeholder footprint zero.
    let _ = build;
}
