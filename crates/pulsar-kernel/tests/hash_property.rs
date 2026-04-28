//! Property tests for `pulsar_kernel::crypto::hash`.
//!
//! Per plan Section XVII.19 testing convention, property tests verify
//! invariants that hold for all admissible inputs (vs known-answer
//! tests that verify specific reference vectors). Each property below
//! is run by `proptest` against randomly-generated inputs across the
//! full range of input lengths the FFI surface accepts.
//!
//! Phase 1.1.B.1 ships these properties for the hash family. Phases
//! 1.1.B.2 (AEAD) and 1.1.B.3 (sign/KEM) extend the property set with
//! round-trip and verification invariants.

// `expect`/`unwrap` are allowed in tests per CLAUDE.md §5 — panic on
// test-vector decode failure or unexpected primitive errors is the
// idiomatic way to surface a regression.
#![allow(clippy::expect_used, clippy::unwrap_used)]

use pulsar_kernel::crypto::hash::{HashAlgorithm, Hasher, hash};

/// Every supported hash algorithm.
const ALGORITHMS: &[HashAlgorithm] = &[
    HashAlgorithm::Sha256,
    HashAlgorithm::Sha384,
    HashAlgorithm::Sha512,
    HashAlgorithm::Sha3_256,
    HashAlgorithm::Sha3_384,
    HashAlgorithm::Sha3_512,
    HashAlgorithm::Blake2b512,
    HashAlgorithm::Blake2s256,
];

proptest::proptest! {
    /// One-shot hash output length matches the algorithm's declared digest length.
    /// Asserts the FFI substrate respects `HashAlgorithm::digest_len()` for every
    /// supported algorithm.
    #[test]
    fn oneshot_output_length_matches_digest_len(input in proptest::collection::vec(proptest::num::u8::ANY, 0..4096)) {
        for &algo in ALGORITHMS {
            let digest = hash(algo, &input).expect("hash should not fail under 4 KiB");
            proptest::prop_assert_eq!(digest.len(), algo.digest_len());
        }
    }

    /// One-shot hash is deterministic — same input produces same output across
    /// repeated invocations. Required for content-addressing + Merkle-tree
    /// verification semantics.
    #[test]
    fn oneshot_is_deterministic(input in proptest::collection::vec(proptest::num::u8::ANY, 0..4096)) {
        for &algo in ALGORITHMS {
            let first = hash(algo, &input).expect("hash should succeed");
            let second = hash(algo, &input).expect("hash should succeed");
            proptest::prop_assert_eq!(first, second);
        }
    }

    /// Streaming hash with a single chunk equals the one-shot output.
    /// Validates the EverCrypt incremental state-machine invariants
    /// (init → update(x) → finalize) for every supported algorithm.
    #[test]
    fn streaming_single_chunk_equals_oneshot(input in proptest::collection::vec(proptest::num::u8::ANY, 0..4096)) {
        for &algo in ALGORITHMS {
            let oneshot = hash(algo, &input).expect("oneshot should succeed");

            let mut hasher = Hasher::new(algo).expect("Hasher::new should succeed");
            hasher.update(&input).expect("update should succeed");
            let streaming = hasher.finalize();

            proptest::prop_assert_eq!(oneshot, streaming);
        }
    }

    /// Streaming hash with two chunks equals the one-shot output regardless
    /// of split point. Validates the EverCrypt block-buffering logic does
    /// not corrupt state when input straddles internal block boundaries.
    #[test]
    fn streaming_two_chunks_equals_oneshot(
        input in proptest::collection::vec(proptest::num::u8::ANY, 1..4096),
        split_pct in 0u32..100u32,
    ) {
        let split = (input.len() * (split_pct as usize)) / 100;

        for &algo in ALGORITHMS {
            let oneshot = hash(algo, &input).expect("oneshot should succeed");

            let mut hasher = Hasher::new(algo).expect("Hasher::new should succeed");
            hasher.update(&input[..split]).expect("first update should succeed");
            hasher.update(&input[split..]).expect("second update should succeed");
            let streaming = hasher.finalize();

            proptest::prop_assert_eq!(oneshot, streaming);
        }
    }

    /// Streaming hash with N small chunks equals the one-shot output. Tests
    /// the EverCrypt block-buffering logic on adversarially-fragmented input
    /// (every byte fed as its own chunk).
    #[test]
    fn streaming_per_byte_equals_oneshot(input in proptest::collection::vec(proptest::num::u8::ANY, 0..512)) {
        for &algo in ALGORITHMS {
            let oneshot = hash(algo, &input).expect("oneshot should succeed");

            let mut hasher = Hasher::new(algo).expect("Hasher::new should succeed");
            for chunk in input.chunks(1) {
                hasher.update(chunk).expect("per-byte update should succeed");
            }
            let streaming = hasher.finalize();

            proptest::prop_assert_eq!(oneshot, streaming);
        }
    }

    /// Distinct inputs almost always produce distinct outputs. This is a
    /// weak collision-resistance hint, not a cryptographic proof — but a
    /// regression that produced identical digests for distinct random
    /// inputs of similar length would be a serious FFI bug.
    #[test]
    fn distinct_inputs_yield_distinct_digests(
        a in proptest::collection::vec(proptest::num::u8::ANY, 1..256),
        b in proptest::collection::vec(proptest::num::u8::ANY, 1..256),
    ) {
        proptest::prop_assume!(a != b);
        for &algo in ALGORITHMS {
            let da = hash(algo, &a).expect("hash(a) should succeed");
            let db = hash(algo, &b).expect("hash(b) should succeed");
            proptest::prop_assert_ne!(da, db, "{:?} produced identical digests for distinct inputs", algo);
        }
    }
}
