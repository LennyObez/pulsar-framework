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

    // Detect whether HACL* C sources are present. We specifically look for
    // .c files under hacl-c/src/ (the canonical layout HACL* ships) rather
    // than "any non-.gitkeep entry" — the latter would flip to true as soon
    // as a stray hacl-c/include/ directory is created without actual source
    // vendoring, which would disable `hacl_placeholder` while still
    // producing no native library. This stricter detection ensures Sprint
    // 1.1 cannot land in a half-vendored "neither placeholder nor compiled"
    // state.
    let hacl_src_dir = std::path::Path::new("hacl-c/src");
    let c_sources: Vec<_> = hacl_src_dir
        .read_dir()
        .into_iter()
        .flatten()
        .flatten()
        .filter(|e| {
            e.path()
                .extension()
                .and_then(|ext| ext.to_str())
                .is_some_and(|ext| ext == "c")
        })
        .map(|e| e.path())
        .collect();

    if c_sources.is_empty() {
        // Phase 0 placeholder: HACL* C distribution lands at Sprint 1.1.
        println!("cargo:rustc-cfg=hacl_placeholder");
        println!("cargo:warning=pulsar-crypto-hacl-bindings: hacl-c/src/ has no .c files (Phase 0 placeholder); FFI symbols will be unavailable until Sprint 1.1.");
        return;
    }

    // Sprint 1.1 + later: compile the vendored HACL* C distribution.
    let mut build = cc::Build::new();
    build
        .include("hacl-c/include")
        .flag_if_supported("-Wall")
        .flag_if_supported("-Wextra")
        .flag_if_supported("-O3");

    for src in &c_sources {
        build.file(src);
    }

    build.compile("hacl_pulsar");
}
