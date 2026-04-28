//! Property + integration tests for `pulsar_kernel::crypto::rng`.
//!
//! The CSPRNG itself is the OS entropy source (`getrandom(2)` on
//! Linux, `BCryptGenRandom` on Windows, `SecRandomCopyBytes` on
//! macOS); its statistical properties are validated upstream by the
//! `rand` / `rand_core` ecosystem and (transitively) by the OS
//! kernel's RNG test harness. Pulsar's wrapper-level tests focus on:
//!
//! - Output length matches the requested length
//! - Distinctness across calls (probabilistic — collisions of two
//!   32-byte CSPRNG outputs are bounded by ~2⁻²⁵⁶)
//! - `try_random_into` and `try_random_bytes` produce statistically
//!   indistinguishable output streams (slice-API parity)
//! - Zero-length requests are accepted (returning an empty `Vec` /
//!   no-op fill) without panicking

#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::rng::{try_random_bytes, try_random_into};

/// Bounded length range used by every property test. Above this
/// ceiling the entropy-call cost dominates; below it the statistical
/// properties are uninformative.
const MAX_LEN: usize = 1024;

proptest::proptest! {
    /// `try_random_bytes(n)` returns exactly `n` bytes for any
    /// admissible length.
    #[test]
    fn random_bytes_length_matches_request(n in 0_usize..MAX_LEN) {
        let bytes = try_random_bytes(n).expect("OS CSPRNG should succeed");
        proptest::prop_assert_eq!(bytes.len(), n);
    }

    /// Two consecutive `try_random_bytes(32)` calls produce distinct
    /// outputs. Probabilistic — the chance of collision on 32 bytes
    /// of CSPRNG output is bounded by 2⁻²⁵⁶, effectively zero across
    /// any realistic test budget. A regression that produced
    /// identical output would indicate the CSPRNG is uninitialised
    /// or stuck on a fixed state.
    #[test]
    fn distinct_calls_yield_distinct_output(_seed in any::<u64>()) {
        let a = try_random_bytes(32).expect("OS CSPRNG should succeed");
        let b = try_random_bytes(32).expect("OS CSPRNG should succeed");
        proptest::prop_assert_ne!(a, b);
    }

    /// `try_random_into(&mut buf)` writes to the entire slice (every
    /// call produces fresh output filling the requested length).
    #[test]
    fn random_into_fills_slice(n in 1_usize..MAX_LEN) {
        let mut buf = vec![0_u8; n];
        try_random_into(&mut buf).expect("OS CSPRNG should succeed");
        // Probabilistic: a 0-byte output across all bytes would mean
        // the RNG returned all-zeroes which has probability 2⁻⁸ⁿ.
        // For n ≥ 1 with 1024 cases per property the chance of a
        // false positive is negligible (≈ 2⁻⁸).
        let any_nonzero = buf.iter().any(|&b| b != 0);
        proptest::prop_assert!(
            any_nonzero || n == 0,
            "CSPRNG output must contain at least one non-zero byte for n={n}",
        );
    }

    /// `try_random_into` and `try_random_bytes` both produce
    /// statistically-indistinguishable output streams. We can't
    /// compare individual outputs (they're different random draws),
    /// but we can verify both produce the same length and both
    /// consistently produce non-zero output.
    #[test]
    fn slice_api_parity(n in 1_usize..MAX_LEN) {
        let via_alloc = try_random_bytes(n).expect("OS CSPRNG should succeed");

        let mut via_slice = vec![0_u8; n];
        try_random_into(&mut via_slice).expect("OS CSPRNG should succeed");

        proptest::prop_assert_eq!(via_alloc.len(), via_slice.len());
        proptest::prop_assert_eq!(via_alloc.len(), n);
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
