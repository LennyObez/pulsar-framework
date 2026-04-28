//! Property tests for `pulsar_kernel::crypto::ml_kem` (ML-KEM-768).
//!
//! Per plan Section XVII.19 testing convention. Properties cover the
//! security-critical ML-KEM-768 invariants:
//!
//! - Encap/decap round-trip across random seed-derived keypairs
//!   recovers the shared secret
//! - Distinct keypair-generation seeds yield distinct keypairs
//!   (probabilistic — collision on 1184-byte public keys is bounded
//!   by 2⁻⁹⁰⁰⁰ish)
//! - Distinct encapsulation seeds yield distinct ciphertexts under
//!   the same public key
//! - Implicit rejection is deterministic — for a fixed (private_key,
//!   tampered_ciphertext) pair, decapsulation produces the same
//!   shared secret on repeat calls
//! - The rejection secret is uncorrelated with the encapsulator's
//!   secret (probabilistic — collision on 32-byte secrets is 2⁻²⁵⁶)

#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::ml_kem::{MlKem768Ciphertext, MlKem768KeyPair};
use secrecy::{ExposeSecret, SecretBox};

/// Strategy producing a 64-byte keypair-generation seed.
fn keygen_seed() -> impl Strategy<Value = Vec<u8>> {
    proptest::collection::vec(any::<u8>(), 64..=64)
}

/// Strategy producing a 32-byte encapsulation seed.
fn encap_seed() -> impl Strategy<Value = Vec<u8>> {
    proptest::collection::vec(any::<u8>(), 32..=32)
}

fn make_seed(bytes: Vec<u8>) -> SecretBox<[u8]> {
    SecretBox::new(bytes.into_boxed_slice())
}

proptest::proptest! {
    #![proptest_config(ProptestConfig {
        // ML-KEM-768 keypair generation costs ~10s of µs and
        // encap/decap ~10s of µs each — keep cases modest to stay
        // under the test-suite latency budget.
        cases: 32,
        ..ProptestConfig::default()
    })]

    /// Encap/decap round-trip recovers the shared secret for any
    /// `(keygen_seed, encap_seed)` pair. The fundamental ML-KEM
    /// correctness property.
    #[test]
    fn encap_decap_round_trip(
        keygen in keygen_seed(),
        encap in encap_seed(),
    ) {
        let kp = MlKem768KeyPair::try_from_seed(&make_seed(keygen))
            .expect("keypair should succeed");
        let (ct, ss_encap) = kp
            .public_key()
            .try_encapsulate_with_seed(&make_seed(encap))
            .expect("encapsulate should succeed");
        let ss_decap = kp.private_key().decapsulate(&ct);
        proptest::prop_assert_eq!(
            ss_encap.expose_secret(),
            ss_decap.expose_secret(),
            "encap shared secret must equal decap shared secret",
        );
    }

    /// Distinct keypair-generation seeds yield distinct public keys.
    /// Probabilistic — for random 64-byte seeds the chance of
    /// identical 1184-byte public keys is bounded by 2⁻⁹⁰⁰⁰ish,
    /// effectively zero across any test budget.
    #[test]
    fn distinct_keygen_seeds_yield_distinct_public_keys(
        seed_a in keygen_seed(),
        seed_b in keygen_seed(),
    ) {
        proptest::prop_assume!(seed_a != seed_b);

        let kp_a = MlKem768KeyPair::try_from_seed(&make_seed(seed_a))
            .expect("keypair a should succeed");
        let kp_b = MlKem768KeyPair::try_from_seed(&make_seed(seed_b))
            .expect("keypair b should succeed");

        proptest::prop_assert_ne!(
            kp_a.public_key().as_bytes().as_slice(),
            kp_b.public_key().as_bytes().as_slice(),
            "distinct seeds must derive distinct public keys",
        );
    }

    /// Distinct encapsulation seeds yield distinct ciphertexts under
    /// the same public key. Probabilistic — 1088-byte ciphertext
    /// collision is bounded by 2⁻⁸⁷⁰⁴.
    #[test]
    fn distinct_encap_seeds_yield_distinct_ciphertexts(
        keygen in keygen_seed(),
        encap_a in encap_seed(),
        encap_b in encap_seed(),
    ) {
        proptest::prop_assume!(encap_a != encap_b);

        let kp = MlKem768KeyPair::try_from_seed(&make_seed(keygen))
            .expect("keypair should succeed");

        let (ct_a, _) = kp.public_key()
            .try_encapsulate_with_seed(&make_seed(encap_a))
            .expect("encap a should succeed");
        let (ct_b, _) = kp.public_key()
            .try_encapsulate_with_seed(&make_seed(encap_b))
            .expect("encap b should succeed");

        proptest::prop_assert_ne!(
            ct_a.as_bytes().as_slice(),
            ct_b.as_bytes().as_slice(),
            "distinct encap seeds must produce distinct ciphertexts",
        );
    }

    /// Implicit rejection is deterministic — flipping a bit in the
    /// ciphertext, decapsulating twice under the same private key
    /// yields byte-identical rejection secrets (FIPS 203 § 7.3
    /// implicit-rejection branch is a pure function).
    #[test]
    fn implicit_rejection_is_deterministic(
        keygen in keygen_seed(),
        encap in encap_seed(),
        flip_byte_idx in 0_usize..1088,
        flip_bit_idx in 0_u8..8,
    ) {
        let kp = MlKem768KeyPair::try_from_seed(&make_seed(keygen))
            .expect("keypair should succeed");
        let (ct, _) = kp.public_key()
            .try_encapsulate_with_seed(&make_seed(encap))
            .expect("encap should succeed");

        let mut tampered_bytes = *ct.as_bytes();
        tampered_bytes[flip_byte_idx] ^= 1_u8 << flip_bit_idx;
        let tampered = MlKem768Ciphertext::from_bytes(tampered_bytes);

        let secret_a = kp.private_key().decapsulate(&tampered);
        let secret_b = kp.private_key().decapsulate(&tampered);

        proptest::prop_assert_eq!(
            secret_a.expose_secret(),
            secret_b.expose_secret(),
            "implicit rejection must be deterministic for fixed (sk, ct)",
        );
    }

    /// Implicit rejection produces an uncorrelated shared secret —
    /// for a tampered ciphertext, the decapsulation output differs
    /// from the encapsulator's authentic shared secret. Probabilistic
    /// — collision is bounded by 2⁻²⁵⁶ on 32-byte outputs.
    #[test]
    fn implicit_rejection_secret_differs_from_authentic(
        keygen in keygen_seed(),
        encap in encap_seed(),
        flip_byte_idx in 0_usize..1088,
        flip_bit_idx in 0_u8..8,
    ) {
        let kp = MlKem768KeyPair::try_from_seed(&make_seed(keygen))
            .expect("keypair should succeed");
        let (ct, encap_secret) = kp.public_key()
            .try_encapsulate_with_seed(&make_seed(encap))
            .expect("encap should succeed");

        let mut tampered_bytes = *ct.as_bytes();
        tampered_bytes[flip_byte_idx] ^= 1_u8 << flip_bit_idx;
        let tampered = MlKem768Ciphertext::from_bytes(tampered_bytes);

        let rejection_secret = kp.private_key().decapsulate(&tampered);

        proptest::prop_assert_ne!(
            rejection_secret.expose_secret(),
            encap_secret.expose_secret(),
            "tampered ciphertext must not decap to the authentic shared secret",
        );
    }
}
