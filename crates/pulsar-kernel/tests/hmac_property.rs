//! Property tests for `pulsar_kernel::crypto::hmac`.
//!
//! Per plan Section XVII.19 testing convention. Each property is run
//! by `proptest` against randomly-generated keys + messages drawn from
//! the bounded strategies defined in this file. The properties below
//! are the security-critical HMAC invariants: round-trip recovery,
//! determinism, slice-API parity, single-bit tamper detection, and
//! distinctness across keys + messages.
//!
//! Inputs cover all five supported algorithms (HMAC-SHA-2-256/384/512
//! plus HMAC-BLAKE2b/2s). Keys are fixed at 32 bytes of random material
//! (above the security floor for SHA-256 / BLAKE2s and meeting the
//! recommendation for the 64-byte-output algorithms); messages span
//! 0–1024 random bytes. BLAKE2 paths get coverage via property tests
//! since RFC 7693 lacks reference KATs.

#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::hmac::{HmacAlgorithm, HmacKey};
use pulsar_kernel::error::Error;
use secrecy::SecretBox;

const ALGORITHMS: &[HmacAlgorithm] = &[
    HmacAlgorithm::Sha256,
    HmacAlgorithm::Sha384,
    HmacAlgorithm::Sha512,
    HmacAlgorithm::Blake2b512,
    HmacAlgorithm::Blake2s256,
];

/// Strategy producing (algorithm, 32-byte key) pairs. Every supported
/// algorithm appears with equal weight under `prop_oneof!`. Keys are
/// 32-byte random material — sufficient for any of the five algorithms
/// (HMAC accepts variable-length keys; 32 bytes meets the security
/// floor for SHA-256 / BLAKE2s and exceeds the recommendation for the
/// other three).
fn algo_with_key() -> impl Strategy<Value = (HmacAlgorithm, Vec<u8>)> {
    let key_strategy = proptest::collection::vec(any::<u8>(), 32..=32);
    (proptest::sample::select(ALGORITHMS), key_strategy)
}

fn make_key(bytes: Vec<u8>) -> SecretBox<[u8]> {
    SecretBox::new(bytes.into_boxed_slice())
}

proptest::proptest! {
    /// Compute then verify the produced tag returns Ok(()) for every
    /// (algo, key, message). The fundamental HMAC correctness property.
    #[test]
    fn compute_verify_round_trip(
        (algo, key_bytes) in algo_with_key(),
        message in proptest::collection::vec(any::<u8>(), 0..1024),
    ) {
        let hmac = HmacKey::new(algo, make_key(key_bytes)).expect("HmacKey::new should succeed");
        let tag = hmac.compute(&message).expect("compute should succeed");
        proptest::prop_assert_eq!(tag.len(), algo.tag_len());
        hmac.verify(&message, &tag)
            .expect("verify should succeed on authentic tag");
    }

    /// HMAC computation is deterministic — the same (key, message)
    /// always produces the same tag. RFC 2104 § 2 makes this explicit
    /// via the construction definition.
    #[test]
    fn computation_is_deterministic(
        (algo, key_bytes) in algo_with_key(),
        message in proptest::collection::vec(any::<u8>(), 0..512),
    ) {
        let hmac = HmacKey::new(algo, make_key(key_bytes)).expect("HmacKey::new should succeed");
        let tag_a = hmac.compute(&message).expect("compute a should succeed");
        let tag_b = hmac.compute(&message).expect("compute b should succeed");
        proptest::prop_assert_eq!(
            tag_a,
            tag_b,
            "HMAC must be deterministic for fixed (key, message)",
        );
    }

    /// `compute_into` produces the same bytes as `compute` for every
    /// (algo, key, message). Validates the slice-API parity with the
    /// Vec-allocating API.
    #[test]
    fn compute_into_matches_compute(
        (algo, key_bytes) in algo_with_key(),
        message in proptest::collection::vec(any::<u8>(), 0..512),
    ) {
        let hmac = HmacKey::new(algo, make_key(key_bytes)).expect("HmacKey::new should succeed");
        let via_compute = hmac.compute(&message).expect("compute should succeed");
        let mut via_into = vec![0_u8; algo.tag_len()];
        hmac.compute_into(&message, &mut via_into)
            .expect("compute_into should succeed");
        proptest::prop_assert_eq!(via_compute, via_into);
    }

    /// Flipping any single bit of the tag causes `verify` to return
    /// `MacVerifyFailed`. Validates the constant-time tag comparison
    /// + integrity property.
    #[test]
    fn verify_rejects_tampered_tag(
        (algo, key_bytes) in algo_with_key(),
        message in proptest::collection::vec(any::<u8>(), 0..256),
        flip_byte_idx in 0_usize..64,
        flip_bit_idx in 0_u8..8,
    ) {
        let hmac = HmacKey::new(algo, make_key(key_bytes)).expect("HmacKey::new should succeed");
        let mut tag = hmac.compute(&message).expect("compute should succeed");

        let actual_idx = flip_byte_idx % tag.len();
        tag[actual_idx] ^= 1_u8 << flip_bit_idx;

        match hmac.verify(&message, &tag) {
            Err(Error::MacVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "tampered tag must fail with MacVerifyFailed; got {other:?}",
            ),
        }
    }

    /// Flipping any single bit of the message causes `verify` to
    /// return `MacVerifyFailed` against the original tag. Validates
    /// that HMAC binds the tag to the exact message bytes.
    #[test]
    fn verify_rejects_tampered_message(
        (algo, key_bytes) in algo_with_key(),
        message in proptest::collection::vec(any::<u8>(), 1..256),
        flip_byte_idx in 0_usize..512,
        flip_bit_idx in 0_u8..8,
    ) {
        let hmac = HmacKey::new(algo, make_key(key_bytes)).expect("HmacKey::new should succeed");
        let tag = hmac.compute(&message).expect("compute should succeed");

        // Move `message` into `tampered`; the original is unused after
        // the initial `compute`.
        let mut tampered = message;
        let actual_idx = flip_byte_idx % tampered.len();
        tampered[actual_idx] ^= 1_u8 << flip_bit_idx;

        match hmac.verify(&tampered, &tag) {
            Err(Error::MacVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "tampered message must fail with MacVerifyFailed; got {other:?}",
            ),
        }
    }

    /// Distinct keys produce distinct tags for the same (algo, message).
    /// Probabilistic — for random 32-byte keys the chance of identical
    /// tags is negligible (≈ 2⁻²⁵⁶ for SHA-256 output).
    #[test]
    fn distinct_keys_yield_distinct_tags(
        algo in proptest::sample::select(ALGORITHMS),
        key_a_bytes in proptest::collection::vec(any::<u8>(), 32..=32),
        key_b_bytes in proptest::collection::vec(any::<u8>(), 32..=32),
        message in proptest::collection::vec(any::<u8>(), 1..256),
    ) {
        proptest::prop_assume!(key_a_bytes != key_b_bytes);

        let hmac_a = HmacKey::new(algo, make_key(key_a_bytes)).expect("HmacKey::new(a) should succeed");
        let hmac_b = HmacKey::new(algo, make_key(key_b_bytes)).expect("HmacKey::new(b) should succeed");

        let tag_a = hmac_a.compute(&message).expect("compute a should succeed");
        let tag_b = hmac_b.compute(&message).expect("compute b should succeed");

        proptest::prop_assert_ne!(
            tag_a,
            tag_b,
            "distinct {:?} keys must produce distinct tags",
            algo,
        );
    }

    /// Distinct messages produce distinct tags under the same (algo, key).
    /// Probabilistic — same negligible-collision argument as above.
    #[test]
    fn distinct_messages_yield_distinct_tags(
        (algo, key_bytes) in algo_with_key(),
        msg_a in proptest::collection::vec(any::<u8>(), 1..256),
        msg_b in proptest::collection::vec(any::<u8>(), 1..256),
    ) {
        proptest::prop_assume!(msg_a != msg_b);

        let hmac = HmacKey::new(algo, make_key(key_bytes)).expect("HmacKey::new should succeed");
        let tag_a = hmac.compute(&msg_a).expect("compute a should succeed");
        let tag_b = hmac.compute(&msg_b).expect("compute b should succeed");

        proptest::prop_assert_ne!(
            tag_a,
            tag_b,
            "distinct messages must produce distinct {:?} tags",
            algo,
        );
    }
}
