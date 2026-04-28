//! Property + integration tests for `pulsar_kernel::crypto::rng`.
//!
//! The CSPRNG itself is the OS entropy source (`getrandom(2)` on
//! Linux, `BCryptGenRandom` on Windows, `SecRandomCopyBytes` on
//! macOS); its statistical properties are validated upstream by the
//! `rand` / `rand_core` ecosystem and (transitively) by the OS
//! kernel's RNG test harness. Pulsar's wrapper-level tests focus on
//! deterministic invariants only:
//!
//! - Output length matches the requested length
//! - Distinctness across calls (probabilistic — collisions of two
//!   32-byte CSPRNG outputs are bounded by 2⁻²⁵⁶, effectively zero
//!   across any realistic test budget)
//! - Zero-length requests are accepted (returning an empty `Vec` /
//!   no-op fill) without panicking
//!
//! Per the /review 405 flakiness analysis, distribution-shape
//! assertions ("at least one non-zero byte") are deliberately absent
//! — they conflict with the CSPRNG's contract of producing uniform
//! random output, which legitimately includes the all-zero output for
//! short slices. Statistical-output validation belongs in the
//! upstream `rand_core` test suite, not in Pulsar's wrapper-level
//! tests.

#![allow(clippy::expect_used, clippy::unwrap_used)]

use pulsar_kernel::crypto::rng::{try_random_bytes, try_random_into};

/// Bounded length range used by every property test. Above this
/// ceiling the entropy-call cost dominates; below it the
/// length-invariant properties remain meaningful.
const MAX_LEN: usize = 1024;

proptest::proptest! {
    /// `try_random_bytes(n)` returns exactly `n` bytes for any
    /// admissible length.
    #[test]
    fn random_bytes_length_matches_request(n in 0_usize..MAX_LEN) {
        let bytes = try_random_bytes(n).expect("OS CSPRNG should succeed");
        proptest::prop_assert_eq!(bytes.len(), n);
    }

    /// `try_random_into(&mut buf)` succeeds and preserves the buffer
    /// length — the wrapper does not truncate or grow the
    /// caller-provided slice.
    #[test]
    fn random_into_preserves_slice_length(n in 0_usize..MAX_LEN) {
        let mut buf = vec![0_u8; n];
        try_random_into(&mut buf).expect("OS CSPRNG should succeed");
        proptest::prop_assert_eq!(buf.len(), n);
    }
}

/// Two consecutive `try_random_bytes(32)` calls produce distinct
/// outputs. Probabilistic — the chance of collision on 32 bytes of
/// CSPRNG output is bounded by 2⁻²⁵⁶. Repeated 256 times to amplify
/// the regression-detection signal — a CSPRNG stuck on a fixed state
/// would fail every iteration; a healthy CSPRNG never collides
/// across this many short draws.
///
/// Implemented as a plain `#[test]` with an explicit loop rather
/// than as a proptest property because the inputs are not generated
/// — every iteration runs the same logic.
#[test]
fn distinct_calls_yield_distinct_output() {
    for _ in 0..256 {
        let a = try_random_bytes(32).expect("OS CSPRNG should succeed");
        let b = try_random_bytes(32).expect("OS CSPRNG should succeed");
        assert_ne!(
            a, b,
            "two consecutive 32-byte CSPRNG draws must not collide",
        );
    }
}

/// `try_random_bytes(0)` returns an empty `Vec` without panicking.
#[test]
fn random_bytes_zero_length_accepted() {
    let bytes = try_random_bytes(0).expect("OS CSPRNG should succeed");
    assert!(bytes.is_empty());
}

/// `try_random_into(&mut [])` is a no-op without panicking.
#[test]
fn random_into_empty_slice_accepted() {
    let mut empty: [u8; 0] = [];
    try_random_into(&mut empty).expect("OS CSPRNG should succeed");
}
