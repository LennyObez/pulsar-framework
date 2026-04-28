//! Build script for `pulsar-crypto-hacl-bindings`.
//!
//! Compiles the vendored HACL\* C distribution into a static archive
//! `libhacl_pulsar.a` linked into the binding crate. The vendored sources
//! live under `hacl-c/` per Decision 2.57 SLSA Level 4 reproducibility:
//! `hacl-c/HACL_VERSION` records the upstream commit SHA, and
//! `hacl-c/MANIFEST.sha256` captures the SHA-256 of every vendored file
//! so re-vendoring can be byte-verified against the manifest.
//!
//! Per Decision 2.60 + ADR-0009 amendment, this crate covers ONLY the
//! 12 classical primitives Pulsar uses (AEAD, X25519, P-256, Ed25519,
//! SHA-2/3, BLAKE2, HKDF, HMAC). The vendored `hacl-c/src/` directory
//! contains the full HACL\* dist/gcc-compatible/ source tree (so all
//! `#include` paths resolve correctly), but only the curated subsets
//! below are actually compiled into the static archive. Files not in
//! any list (Frodo PQC, HPKE, K256 ECDSA, NaCl, RSA-PSS, Salsa20, MD5,
//! SHA-1, DRBG, FFDHE, debug helpers) remain on disk for audit clarity
//! but never enter the link surface.
//!
//! Per-file CFLAGS handling: SIMD-128 sources require `-mavx`, SIMD-256
//! sources require `-mavx -mavx2` on x86_64 (mirrors HACL\*'s
//! `Makefile.config` `CFLAGS_128` / `CFLAGS_256`). To apply per-file
//! flags we split compilation into three `cc::Build` instances —
//! portable, simd128, simd256 — that each emit a separate static
//! archive linked together by Cargo's standard linker pipeline.
//!
//! NIST PQC primitives (ML-KEM-768/1024, ML-DSA-65/87) are sourced from
//! the libcrux Rust-native workspace dependencies and do NOT flow through
//! this build script.

// Build scripts run once per build and panic-on-setup-failure is the
// idiomatic error-reporting path: Cargo surfaces the panic as a build
// error with the appropriate context. The workspace clippy policy
// (deny `unwrap_used` / `expect_used`) targets library code paths
// where panics would propagate to runtime; build scripts are exempt.
//
// `type_complexity` is allowed because the Vale ASM dispatch table is
// build-time configuration data; flattening it via type aliases would
// add indirection without runtime impact.
//
// `panic` is allowed because the partial-vendoring detection panics
// loudly to surface a build-time integrity failure (per /review 400
// item #5) — silent fallback to placeholder mode would mask a real bug.
#![allow(
    clippy::unwrap_used,
    clippy::expect_used,
    clippy::panic,
    clippy::type_complexity
)]

use std::io::Write;
use std::path::{Path, PathBuf};

/// Portable C sources — no per-file CFLAGS needed beyond the shared base
/// flags. Compiled into `libhacl_pulsar.a`.
const HACL_C_SOURCES_PORTABLE: &[&str] = &[
    // EverCrypt abstraction layer (multiplexes per CPU feature)
    "EverCrypt_AEAD.c",
    "EverCrypt_AutoConfig2.c",
    "EverCrypt_Chacha20Poly1305.c",
    "EverCrypt_Cipher.c",
    "EverCrypt_Curve25519.c",
    "EverCrypt_Ed25519.c",
    "EverCrypt_HKDF.c",
    "EverCrypt_HMAC.c",
    "EverCrypt_Hash.c",
    "EverCrypt_Poly1305.c",
    // ChaCha20-Poly1305 AEAD (portable)
    "Hacl_AEAD_Chacha20Poly1305.c",
    "Hacl_Chacha20.c",
    "Hacl_Chacha20_Vec32.c",
    "Hacl_MAC_Poly1305.c",
    // Bignum primitives (X25519 / Ed25519 / P-256 substrate)
    "Hacl_Bignum.c",
    "Hacl_Bignum256.c",
    "Hacl_Bignum256_32.c",
    "Hacl_Bignum64.c",
    "Hacl_GenericField32.c",
    "Hacl_GenericField64.c",
    // X25519 (Curve25519 ECDH)
    "Hacl_Curve25519_51.c",
    "Hacl_Curve25519_64.c",
    // Ed25519 (signature)
    "Hacl_EC_Ed25519.c",
    "Hacl_Ed25519.c",
    // P-256 (NIST curve)
    "Hacl_P256.c",
    // SHA-2 + SHA-3 + BLAKE2 hashes (portable)
    "Hacl_Hash_Base.c",
    "Hacl_Hash_SHA2.c",
    "Hacl_Hash_SHA3.c",
    "Hacl_Hash_Blake2b.c",
    "Hacl_Hash_Blake2s.c",
    // Deprecated hashes — referenced by EverCrypt_Hash.c's dispatcher
    // (the runtime alg switch in EverCrypt_Hash_Incremental_hash). The
    // safe wrappers in `pulsar-kernel::crypto` never expose these
    // algorithms; the symbols exist only to satisfy the dispatcher's
    // link surface. The 12 primitives Pulsar exposes per Decision 2.60
    // remain unchanged.
    //
    // Phase 1.1.B safe-wrappers will encode the supported-hash invariant
    // in code via a `pub(crate) const SUPPORTED_HASHES` allow-list +
    // exhaustive `match` on `Spec_Hash_Definitions_*` so MD5 + SHA-1
    // cannot reach any public API even if EverCrypt's dispatcher accepts
    // them. This compile-time assertion lives in `pulsar-kernel::crypto`
    // because that's where the typed wrapper boundary sits — the C
    // compile order here has no equivalent enforcement mechanism beyond
    // selective compilation, which the deprecated-hash dispatcher
    // dependency rules out.
    "Hacl_Hash_MD5.c",
    "Hacl_Hash_SHA1.c",
    // HKDF + HMAC + Streaming HMAC (portable)
    "Hacl_HKDF.c",
    "Hacl_HMAC.c",
    "Hacl_Streaming_HMAC.c",
    // Vale shim (CPU dispatch for AES-NI / SHA-NI / Curve25519 acceleration)
    "Vale.c",
    // Lib helpers — explicit zeroization for `Secret<T>` lifecycle
    "Lib_Memzero0.c",
];

/// SIMD-128 (AVX / SSE 128-bit vector) sources — compiled with `-mavx`
/// on x86_64. On targets where `HACL_CAN_COMPILE_VEC128 = 0` these
/// sources are skipped entirely and the EverCrypt dispatcher falls back
/// to the portable code path at runtime.
const HACL_C_SOURCES_SIMD128: &[&str] = &[
    "Hacl_AEAD_Chacha20Poly1305_Simd128.c",
    "Hacl_Chacha20_Vec128.c",
    "Hacl_MAC_Poly1305_Simd128.c",
    "Hacl_Hash_Blake2s_Simd128.c",
    "Hacl_HMAC_Blake2s_128.c",
    "Hacl_HKDF_Blake2s_128.c",
    "Hacl_SHA2_Vec128.c",
];

/// SIMD-256 (AVX2 256-bit vector) sources — compiled with `-mavx -mavx2`
/// on x86_64. Skipped on targets where `HACL_CAN_COMPILE_VEC256 = 0`
/// (notably aarch64, x86 32-bit).
const HACL_C_SOURCES_SIMD256: &[&str] = &[
    "Hacl_AEAD_Chacha20Poly1305_Simd256.c",
    "Hacl_Chacha20_Vec256.c",
    "Hacl_MAC_Poly1305_Simd256.c",
    "Hacl_Hash_Blake2b_Simd256.c",
    "Hacl_HMAC_Blake2b_256.c",
    "Hacl_HKDF_Blake2b_256.c",
    "Hacl_SHA2_Vec256.c",
    "Hacl_Hash_SHA3_Simd256.c",
];

/// Vale-generated assembly variants per (`target_os`, `target_arch`). One
/// variant per (algo, platform) tuple is selected at build time.
///
/// `(algo_prefix, &[(target_os, target_arch, file_suffix)])`. A target
/// tuple absent from the inner slice means the algo has no assembly
/// path on that platform (the portable C fallback inside
/// `HACL_C_SOURCES_PORTABLE` stays correct, only performance differs).
const VALE_ASM_ALGOS: &[(&str, &[(&str, &str, &str)])] = &[
    (
        "aesgcm",
        &[
            ("linux", "x86_64", "x86_64-linux.S"),
            ("macos", "x86_64", "x86_64-darwin.S"),
            ("windows", "x86_64", "x86_64-mingw.S"),
            ("linux", "powerpc64", "ppc64le.S"),
        ],
    ),
    (
        "cpuid",
        &[
            ("linux", "x86_64", "x86_64-linux.S"),
            ("macos", "x86_64", "x86_64-darwin.S"),
            ("windows", "x86_64", "x86_64-mingw.S"),
        ],
    ),
    (
        "curve25519",
        &[
            ("linux", "x86_64", "x86_64-linux.S"),
            ("macos", "x86_64", "x86_64-darwin.S"),
            ("windows", "x86_64", "x86_64-mingw.S"),
        ],
    ),
    (
        "poly1305",
        &[
            ("linux", "x86_64", "x86_64-linux.S"),
            ("macos", "x86_64", "x86_64-darwin.S"),
            ("windows", "x86_64", "x86_64-mingw.S"),
        ],
    ),
    (
        "sha256",
        &[
            ("linux", "x86_64", "x86_64-linux.S"),
            ("macos", "x86_64", "x86_64-darwin.S"),
            ("windows", "x86_64", "x86_64-mingw.S"),
            ("linux", "powerpc64", "ppc64le.S"),
        ],
    ),
];

fn main() {
    println!("cargo:rerun-if-changed=hacl-c/");
    println!("cargo:rerun-if-changed=build.rs");

    // The `hacl_placeholder` cfg is retained so a future re-vendoring step
    // can clear `hacl-c/` for re-extraction without breaking the workspace
    // build. With sources present (vendored at Sprint 1.1.A) the cfg stays
    // unset; the safe-wrapper crate `pulsar-kernel::crypto` consumes the
    // FFI surface unconditionally.
    println!("cargo::rustc-check-cfg=cfg(hacl_placeholder)");

    let hacl_src_dir = PathBuf::from("hacl-c/src");
    let hacl_include_dir = PathBuf::from("hacl-c/include");
    let krml_include_dir = hacl_include_dir.join("krml");
    let krmllib_minimal_dir = hacl_include_dir.join("krmllib/dist/minimal");

    // Three vendoring states:
    //   - all 0 portable sources present → fully-vendored, compile normally
    //   - 0 portable sources present     → manifest-clean checkout, placeholder
    //   - partial vendoring              → fail loudly (someone added sources
    //                                       to the curated list without
    //                                       vendoring them, or `hacl-c/src/`
    //                                       is corrupted; either way silent
    //                                       fallback to placeholder would
    //                                       mask a real bug).
    let present_count = HACL_C_SOURCES_PORTABLE
        .iter()
        .filter(|s| hacl_src_dir.join(s).is_file())
        .count();
    if present_count == 0 {
        println!("cargo:rustc-cfg=hacl_placeholder");
        println!(
            "cargo:warning=pulsar-crypto-hacl-bindings: hacl-c/src/ empty (Phase 0 placeholder); FFI symbols unavailable until tools/scripts/vendor-hacl.sh runs."
        );
        return;
    }
    if present_count != HACL_C_SOURCES_PORTABLE.len() {
        let missing: Vec<&&str> = HACL_C_SOURCES_PORTABLE
            .iter()
            .filter(|s| !hacl_src_dir.join(s).is_file())
            .collect();
        panic!(
            "pulsar-crypto-hacl-bindings: partial HACL* vendoring detected — \
             {} of {} curated sources missing from hacl-c/src/. Re-run \
             tools/scripts/vendor-hacl.sh or update HACL_C_SOURCES_PORTABLE \
             in build.rs. Missing files: {missing:?}",
            HACL_C_SOURCES_PORTABLE.len() - present_count,
            HACL_C_SOURCES_PORTABLE.len(),
        );
    }

    let target_os = std::env::var("CARGO_CFG_TARGET_OS").unwrap_or_default();
    let target_arch = std::env::var("CARGO_CFG_TARGET_ARCH").unwrap_or_default();

    // HACL* sources `#include "config.h"` for compile-time feature flags
    // (TARGET_ARCHITECTURE, HACL_CAN_COMPILE_INTRINSICS / VALE / INLINE_ASM
    // / VEC128 / VEC256 / UINT128). Upstream generates this via the
    // `configure` shell script using host-based feature probes; for SLSA
    // Level 4 reproducibility we generate it deterministically from the
    // target tuple (no runtime probes) into OUT_DIR. The values mirror
    // the canonical `configure` output for each target — see HACL*'s
    // `dist/gcc-compatible/configure` for the probe semantics.
    let cfg = TargetConfig::for_target(&target_arch, &target_os);
    let out_dir = PathBuf::from(std::env::var_os("OUT_DIR").expect("OUT_DIR not set"));
    let config_path = out_dir.join("config.h");
    let mut file = std::fs::File::create(&config_path)
        .expect("pulsar-crypto-hacl-bindings: failed to write OUT_DIR/config.h");
    file.write_all(cfg.to_config_h().as_bytes())
        .expect("pulsar-crypto-hacl-bindings: failed to write config.h contents");

    // ── Portable build (libhacl_pulsar.a) ────────────────────────────
    let mut portable = base_build(
        &hacl_include_dir,
        &krml_include_dir,
        &krmllib_minimal_dir,
        &out_dir,
    );
    for src in HACL_C_SOURCES_PORTABLE {
        portable.file(hacl_src_dir.join(src));
    }
    // Add the appropriate Vale-generated assembly variant for the target.
    // Each algo has a portable C fallback in HACL_C_SOURCES_PORTABLE, so
    // a missing ASM variant degrades performance but does not break
    // correctness.
    if cfg.vale {
        for (algo, variants) in VALE_ASM_ALGOS {
            if let Some((_, _, suffix)) = variants
                .iter()
                .find(|(os, arch, _)| os == &target_os && arch == &target_arch)
            {
                let asm_path = hacl_src_dir.join(format!("{algo}-{suffix}"));
                if asm_path.is_file() {
                    portable.file(&asm_path);
                }
            }
        }
    }
    portable.compile("hacl_pulsar");

    // ── SIMD-128 build (libhacl_pulsar_simd128.a) ────────────────────
    if cfg.vec128 {
        let mut simd128 = base_build(
            &hacl_include_dir,
            &krml_include_dir,
            &krmllib_minimal_dir,
            &out_dir,
        );
        // Per HACL* Makefile.config: CFLAGS_128 = -mavx (matches
        // libintvector.h's expectation that 128-bit Lib_IntVector_Intrinsics_*
        // macros expand to AVX intrinsics).
        simd128.flag_if_supported("-mavx");
        for src in HACL_C_SOURCES_SIMD128 {
            simd128.file(hacl_src_dir.join(src));
        }
        simd128.compile("hacl_pulsar_simd128");
    }

    // ── SIMD-256 build (libhacl_pulsar_simd256.a) ────────────────────
    if cfg.vec256 {
        let mut simd256 = base_build(
            &hacl_include_dir,
            &krml_include_dir,
            &krmllib_minimal_dir,
            &out_dir,
        );
        // Per HACL* Makefile.config: CFLAGS_256 = -mavx -mavx2 (matches
        // libintvector.h's expectation that 256-bit Lib_IntVector_Intrinsics_*
        // macros expand to AVX2 intrinsics).
        simd256
            .flag_if_supported("-mavx")
            .flag_if_supported("-mavx2");
        for src in HACL_C_SOURCES_SIMD256 {
            simd256.file(hacl_src_dir.join(src));
        }
        simd256.compile("hacl_pulsar_simd256");
    }

    // ── Bindgen: generate Rust FFI declarations ─────────────────────
    generate_bindings(
        &hacl_include_dir,
        &krml_include_dir,
        &krmllib_minimal_dir,
        &out_dir,
    );
}

/// Generate `bindings.rs` into OUT_DIR via bindgen, scoped strictly to
/// the 12 classical primitive APIs Pulsar exposes.
///
/// The allowlist below enumerates the public FFI surface. Anything not
/// matching the allowlist is excluded from the generated bindings —
/// audit boundary stays narrow even though the vendored header tree
/// covers the full HACL\* dist.
fn generate_bindings(
    hacl_include_dir: &Path,
    krml_include_dir: &Path,
    krmllib_minimal_dir: &Path,
    out_dir: &Path,
) {
    // Single umbrella header exercises all 12 primitive APIs. Written
    // under OUT_DIR so the vendored tree stays unchanged.
    let wrapper_h_path = out_dir.join("hacl_pulsar_wrapper.h");
    std::fs::write(&wrapper_h_path, WRAPPER_HEADER_CONTENTS.as_bytes())
        .expect("pulsar-crypto-hacl-bindings: failed to write OUT_DIR/hacl_pulsar_wrapper.h");

    let bindings = bindgen::Builder::default()
        .header(wrapper_h_path.to_str().expect("non-UTF8 OUT_DIR path"))
        .clang_arg(format!("-I{}", out_dir.display()))
        .clang_arg(format!("-I{}", hacl_include_dir.display()))
        .clang_arg(format!("-I{}", hacl_include_dir.join("internal").display()))
        .clang_arg(format!("-I{}", krml_include_dir.display()))
        .clang_arg(format!("-I{}", krmllib_minimal_dir.display()))
        // Allowlist only the 12 classical primitives' public APIs.
        // Everything else in the vendored headers (Frodo, HPKE, K256,
        // NaCl, RSA-PSS, Salsa20, MD5, SHA-1, DRBG, FFDHE, debug
        // helpers) is excluded from the generated bindings.
        .allowlist_function("EverCrypt_AEAD_.*")
        .allowlist_function("EverCrypt_AutoConfig2_.*")
        .allowlist_function("EverCrypt_Chacha20Poly1305_.*")
        .allowlist_function("EverCrypt_Curve25519_.*")
        .allowlist_function("EverCrypt_Ed25519_.*")
        .allowlist_function("EverCrypt_HKDF_.*")
        .allowlist_function("EverCrypt_HMAC_.*")
        .allowlist_function("EverCrypt_Hash_.*")
        .allowlist_function("EverCrypt_Poly1305_.*")
        .allowlist_function("Hacl_P256_.*")
        .allowlist_function("Hacl_Streaming_HMAC_.*")
        .allowlist_function("Lib_Memzero0_.*")
        // Allowlist the algorithm-identifier enums + error codes.
        .allowlist_type("Spec_Hash_Definitions_.*")
        .allowlist_type("Spec_Agile_Cipher_.*")
        .allowlist_type("Spec_Agile_AEAD_.*")
        .allowlist_type("EverCrypt_Error_.*")
        .allowlist_var("Spec_Hash_Definitions_.*")
        .allowlist_var("Spec_Agile_Cipher_.*")
        .allowlist_var("Spec_Agile_AEAD_.*")
        // Generate idiomatic Rust enums where possible.
        .default_enum_style(bindgen::EnumVariation::ModuleConsts)
        // No layout tests — they're noisy across glibc versions and
        // contribute nothing once the header semver is pinned via
        // hacl-c/HACL_VERSION.
        .layout_tests(false)
        .generate()
        .expect("pulsar-crypto-hacl-bindings: bindgen failed to generate bindings");

    let bindings_path = out_dir.join("bindings.rs");
    bindings
        .write_to_file(&bindings_path)
        .expect("pulsar-crypto-hacl-bindings: failed to write bindings.rs");
}

/// Umbrella header bindgen consumes. Pulls in only the public headers
/// for the 12 classical primitives — keeps the bindgen surface narrow.
const WRAPPER_HEADER_CONTENTS: &str = r#"#include "EverCrypt_AEAD.h"
#include "EverCrypt_AutoConfig2.h"
#include "EverCrypt_Chacha20Poly1305.h"
#include "EverCrypt_Curve25519.h"
#include "EverCrypt_Ed25519.h"
#include "EverCrypt_HKDF.h"
#include "EverCrypt_HMAC.h"
#include "EverCrypt_Hash.h"
#include "EverCrypt_Poly1305.h"
#include "Hacl_P256.h"
#include "Hacl_Streaming_HMAC.h"
"#;

/// Configure a `cc::Build` with the shared compile flags + include paths.
fn base_build(
    hacl_include_dir: impl AsRef<Path>,
    krml_include_dir: impl AsRef<Path>,
    krmllib_minimal_dir: impl AsRef<Path>,
    out_dir: impl AsRef<Path>,
) -> cc::Build {
    let hacl_include_dir = hacl_include_dir.as_ref();
    let krml_include_dir = krml_include_dir.as_ref();
    let krmllib_minimal_dir = krmllib_minimal_dir.as_ref();
    let out_dir = out_dir.as_ref();
    let mut build = cc::Build::new();
    build
        .include(out_dir) // for generated config.h
        .include(hacl_include_dir)
        .include(hacl_include_dir.join("internal"))
        .include(krml_include_dir)
        .include(krmllib_minimal_dir)
        .flag_if_supported("-std=c11")
        .flag_if_supported("-Wall")
        .flag_if_supported("-Wextra")
        // HACL* extracts code with intentional unused-parameter patterns
        // (F* extraction artefact); silence those without softening the
        // rest of the compiler's warning surface.
        .flag_if_supported("-Wno-unused")
        .flag_if_supported("-Wno-unknown-warning-option")
        .flag_if_supported("-Wno-infinite-recursion")
        // F* extraction emits signed integer wraparound which is
        // well-defined under -fwrapv. Same flag the upstream Makefile
        // uses (Makefile.basic line 18).
        .flag_if_supported("-fwrapv")
        // BSD/POSIX feature exposure expected by some HACL* sources.
        .define("_BSD_SOURCE", None)
        .define("_DEFAULT_SOURCE", None);
    build
}

/// Deterministic per-target HACL\* compile-time feature flags.
struct TargetConfig {
    target_arch_id: &'static str,
    intrinsics: u8,
    vale: bool,
    inline_asm: u8,
    vec128: bool,
    vec256: bool,
    uint128: u8,
}

impl TargetConfig {
    /// Pick the canonical feature set for a target tuple.
    ///
    /// Mirrors the canonical `configure` script output for each target —
    /// see HACL\*'s `dist/gcc-compatible/configure` for the probe
    /// semantics. Conservative defaults: portable C path always
    /// available, SIMD enabled where the architecture supports it
    /// without runtime probing, Vale ASM enabled only on x86_64.
    fn for_target(target_arch: &str, target_os: &str) -> Self {
        match (target_arch, target_os) {
            // x86_64 across all hosted OSes: full feature set. Modern x86_64
            // CPUs ship AVX/AVX2 + Vale-applicable patterns regardless of
            // operating system; the Vale ASM dispatch table below picks the
            // appropriate per-(os, arch) variant or falls back to portable C
            // when no ASM is bundled (e.g., x86_64 FreeBSD / OpenBSD /
            // NetBSD / illumos use the linux-style .S files at link time).
            // The ASM lookup is permissive: if no match exists for the
            // target tuple, the portable C fallback inside
            // HACL_C_SOURCES_PORTABLE remains correct.
            ("x86_64", _) => Self {
                target_arch_id: "TARGET_ARCHITECTURE_ID_X64",
                intrinsics: 1,
                vale: true,
                inline_asm: 1,
                vec128: true,
                vec256: true,
                uint128: 1,
            },
            // aarch64: NEON for 128-bit vectors, no AVX/Vale, has __uint128_t.
            ("aarch64", _) => Self {
                target_arch_id: "TARGET_ARCHITECTURE_ID_ARM8",
                intrinsics: 1,
                vale: false,
                inline_asm: 1,
                vec128: true,
                vec256: false,
                uint128: 1,
            },
            // x86 32-bit: no Vale, no AVX, no __uint128_t (32-bit pointers).
            ("x86", _) => Self {
                target_arch_id: "TARGET_ARCHITECTURE_ID_X86",
                intrinsics: 0,
                vale: false,
                inline_asm: 0,
                vec128: false,
                vec256: false,
                uint128: 0,
            },
            // PowerPC64 little-endian: portable C only on Pulsar's first
            // pass; Vale has ppc64le ASM variants but enabling them
            // requires more build harness work — left at portable for
            // Sprint 1.1.A.
            ("powerpc64", _) => Self {
                target_arch_id: "TARGET_ARCHITECTURE_ID_POWER",
                intrinsics: 0,
                vale: false,
                inline_asm: 0,
                vec128: false,
                vec256: false,
                uint128: 1,
            },
            // Anything else: portable C fallback only.
            _ => Self {
                target_arch_id: "TARGET_ARCHITECTURE_ID_UNKNOWN",
                intrinsics: 0,
                vale: false,
                inline_asm: 0,
                vec128: false,
                vec256: false,
                uint128: 0,
            },
        }
    }

    fn to_config_h(&self) -> String {
        format!(
            "/* Generated by pulsar-crypto-hacl-bindings/build.rs — do not edit manually.\n\
             * Mirrors the canonical HACL* `configure` script output for the build target.\n\
             */\n\
             #define TARGET_ARCHITECTURE {arch}\n\
             #define HACL_CAN_COMPILE_INTRINSICS {intrinsics}\n\
             #define HACL_CAN_COMPILE_VALE {vale}\n\
             #define HACL_CAN_COMPILE_INLINE_ASM {inline_asm}\n\
             #define HACL_CAN_COMPILE_VEC128 {vec128}\n\
             #define HACL_CAN_COMPILE_VEC256 {vec256}\n\
             #define HACL_CAN_COMPILE_UINT128 {uint128}\n",
            arch = self.target_arch_id,
            intrinsics = self.intrinsics,
            vale = u8::from(self.vale),
            inline_asm = self.inline_asm,
            vec128 = u8::from(self.vec128),
            vec256 = u8::from(self.vec256),
            uint128 = self.uint128,
        )
    }
}
