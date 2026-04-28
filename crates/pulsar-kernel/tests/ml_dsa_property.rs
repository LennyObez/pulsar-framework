//! Property tests for `pulsar_kernel::crypto::ml_dsa` (ML-DSA-65).
//!
//! Per plan Section XVII.19 testing convention. Properties cover the
//! security-critical ML-DSA-65 invariants:
//!
//! - Sign/verify round-trip across random `(keygen_seed, signing_seed,
//!   message)` triples
//! - Distinct keypair-generation seeds yield distinct verification
//!   keys (probabilistic — collision on 1 952-byte keys is bounded
//!   by 2⁻¹⁵⁰⁰⁰ish)
//! - Distinct signing seeds yield distinct signatures (the signing
//!   randomness drives FIPS 204's rejection-sampling loop, so
//!   different seeds produce different signatures)
//! - Verification rejects tampered messages → `SignatureVerifyFailed`
//! - Verification rejects wrong verification keys

#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::ml_dsa::MlDsa65KeyPair;
use pulsar_kernel::error::Error;
use secrecy::SecretBox;

/// Strategy producing a 32-byte keypair-generation seed.
fn keygen_seed() -> impl Strategy<Value = Vec<u8>> {
    proptest::collection::vec(any::<u8>(), 32..=32)
}

/// Strategy producing a 32-byte signing randomness seed.
fn signing_seed() -> impl Strategy<Value = Vec<u8>> {
    proptest::collection::vec(any::<u8>(), 32..=32)
}

fn make_seed(bytes: Vec<u8>) -> SecretBox<[u8]> {
    SecretBox::new(bytes.into_boxed_slice())
}

proptest::proptest! {
    #![proptest_config(ProptestConfig {
        // ML-DSA-65 keygen + sign + verify each cost ~100s of µs;
        // keep cases modest to stay under the test-suite latency
        // budget. Per-case ≈ 300 µs × 32 cases × 5 properties ≈ 50 ms.
        cases: 32,
        ..ProptestConfig::default()
    })]

    /// Sign/verify round-trip recovers `Ok(())` for any
    /// `(keygen_seed, signing_seed, message)` triple. The fundamental
    /// ML-DSA correctness property (FIPS 204 § 5).
    #[test]
    fn sign_verify_round_trip(
        keygen in keygen_seed(),
        signing in signing_seed(),
        message in proptest::collection::vec(any::<u8>(), 0..512),
    ) {
        let kp = MlDsa65KeyPair::try_from_seed(&make_seed(keygen))
            .expect("keypair should succeed");
        let sig = kp.signing_key()
            .try_sign_with_seed(&message, b"", &make_seed(signing))
            .expect("sign should succeed");
        kp.verification_key()
            .verify(&message, b"", &sig)
            .expect("authentic signature must verify");
    }

    /// Distinct keypair-generation seeds yield distinct verification
    /// keys. Probabilistic — collision on 1 952-byte keys is bounded
    /// by 2⁻¹⁵⁰⁰⁰ish, effectively zero across any test budget.
    #[test]
    fn distinct_keygen_seeds_yield_distinct_verification_keys(
        seed_a in keygen_seed(),
        seed_b in keygen_seed(),
    ) {
        proptest::prop_assume!(seed_a != seed_b);

        let kp_a = MlDsa65KeyPair::try_from_seed(&make_seed(seed_a))
            .expect("kp a should succeed");
        let kp_b = MlDsa65KeyPair::try_from_seed(&make_seed(seed_b))
            .expect("kp b should succeed");

        proptest::prop_assert_ne!(
            kp_a.verification_key().as_bytes().as_slice(),
            kp_b.verification_key().as_bytes().as_slice(),
            "distinct seeds must derive distinct verification keys",
        );
    }

    /// Distinct signing seeds yield distinct signatures under the
    /// same `(signing_key, message, context)`. ML-DSA's signing
    /// operation is randomised by default (FIPS 204 § 5.4) so
    /// different signing seeds drive the rejection-sampling loop to
    /// different commitments.
    #[test]
    fn distinct_signing_seeds_yield_distinct_signatures(
        keygen in keygen_seed(),
        signing_a in signing_seed(),
        signing_b in signing_seed(),
        message in proptest::collection::vec(any::<u8>(), 1..256),
    ) {
        proptest::prop_assume!(signing_a != signing_b);

        let kp = MlDsa65KeyPair::try_from_seed(&make_seed(keygen))
            .expect("kp should succeed");

        let sig_a = kp.signing_key()
            .try_sign_with_seed(&message, b"", &make_seed(signing_a))
            .expect("sign a should succeed");
        let sig_b = kp.signing_key()
            .try_sign_with_seed(&message, b"", &make_seed(signing_b))
            .expect("sign b should succeed");

        proptest::prop_assert_ne!(
            sig_a.as_bytes().as_slice(),
            sig_b.as_bytes().as_slice(),
            "distinct signing seeds must produce distinct signatures",
        );
    }

    /// Verification rejects tampered messages — single-bit message
    /// flip → `SignatureVerifyFailed`.
    #[test]
    fn verify_rejects_tampered_message(
        keygen in keygen_seed(),
        signing in signing_seed(),
        message in proptest::collection::vec(any::<u8>(), 1..256),
        flip_byte_idx in 0_usize..256,
        flip_bit_idx in 0_u8..8,
    ) {
        let kp = MlDsa65KeyPair::try_from_seed(&make_seed(keygen))
            .expect("kp should succeed");
        let sig = kp.signing_key()
            .try_sign_with_seed(&message, b"", &make_seed(signing))
            .expect("sign should succeed");

        let mut tampered = message;
        let actual_idx = flip_byte_idx % tampered.len();
        tampered[actual_idx] ^= 1_u8 << flip_bit_idx;

        match kp.verification_key().verify(&tampered, b"", &sig) {
            Err(Error::SignatureVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "verify of tampered message must fail; got {other:?}",
            ),
        }
    }

    /// Verification rejects signatures produced by a different
    /// signing key. Probabilistic — for random keypairs the chance
    /// that key A's signature verifies under key B's verification
    /// key is bounded by 2⁻λ where λ is the security parameter
    /// (≈ 192 bits for ML-DSA-65).
    #[test]
    fn verify_rejects_wrong_verification_key(
        seed_a in keygen_seed(),
        seed_b in keygen_seed(),
        signing in signing_seed(),
        message in proptest::collection::vec(any::<u8>(), 0..256),
    ) {
        proptest::prop_assume!(seed_a != seed_b);

        let kp_a = MlDsa65KeyPair::try_from_seed(&make_seed(seed_a))
            .expect("kp a should succeed");
        let kp_b = MlDsa65KeyPair::try_from_seed(&make_seed(seed_b))
            .expect("kp b should succeed");

        let sig_a = kp_a.signing_key()
            .try_sign_with_seed(&message, b"", &make_seed(signing))
            .expect("sign a should succeed");

        match kp_b.verification_key().verify(&message, b"", &sig_a) {
            Err(Error::SignatureVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "verify under wrong key must fail; got {other:?}",
            ),
        }
    }
}
