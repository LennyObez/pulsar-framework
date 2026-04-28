//! Property tests for `pulsar_kernel::crypto::hybrid_kem`.
//!
//! Hybrid X25519+ML-KEM-768 KEM. Properties cover the
//! security-critical hybrid-orchestration invariants:
//!
//! - Encap/decap round-trip across random hybrid keypairs
//! - Distinct keypairs yield distinct public keys (probabilistic)
//! - Distinct encap calls yield distinct ciphertexts under same
//!   public key (because ephemeral X25519 + ML-KEM seeds are fresh
//!   per call)
//! - Wire-format `to_bytes` / `from_bytes` is a perfect round-trip

#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::hybrid_kem::{
    HybridKemCiphertext, HybridKemKeyPair, HybridKemPublicKey,
};
use secrecy::ExposeSecret;

proptest::proptest! {
    #![proptest_config(ProptestConfig {
        // Hybrid keypair gen ~100 µs; encap + decap ~50 µs each.
        // Per-case ≈ 250 µs × 32 cases × 4 properties ≈ 32 ms total.
        cases: 32,
        ..ProptestConfig::default()
    })]

    /// Encap/decap round-trip recovers the combined shared secret.
    /// Driven by proptest entropy via the wrapper's CSPRNG path, so
    /// each case exercises a fresh `(keypair, encap_seeds)` triple.
    #[test]
    fn encap_decap_round_trip(_seed in any::<u64>()) {
        let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
        let (ciphertext, encap_secret) = kp.public_key()
            .try_encapsulate()
            .expect("encap should succeed");
        let decap_secret = kp.private_key()
            .try_decapsulate(&ciphertext)
            .expect("decap should succeed");

        proptest::prop_assert_eq!(
            encap_secret.expose_secret(),
            decap_secret.expose_secret(),
            "hybrid encap shared secret must equal hybrid decap shared secret",
        );
    }

    /// Distinct hybrid keypairs yield distinct public keys
    /// (probabilistic). For two fresh CSPRNG-driven keypairs the
    /// chance of identical X25519 OR ML-KEM public keys is bounded
    /// by 2⁻²⁵⁶ + 2⁻⁹⁰⁰⁰ish, effectively zero.
    #[test]
    fn distinct_keypairs_yield_distinct_public_keys(_seed in any::<u64>()) {
        let kp_a = HybridKemKeyPair::try_generate().expect("kp a should succeed");
        let kp_b = HybridKemKeyPair::try_generate().expect("kp b should succeed");

        proptest::prop_assert_ne!(
            kp_a.public_key().to_bytes(),
            kp_b.public_key().to_bytes(),
            "two fresh hybrid keypairs must yield distinct public keys",
        );
    }

    /// Distinct encap calls under the same public key yield distinct
    /// ciphertexts. Both the X25519 ephemeral key and the ML-KEM
    /// encap seed are fresh per call, so the resulting ciphertext
    /// differs in BOTH halves.
    #[test]
    fn distinct_encap_calls_yield_distinct_ciphertexts(_seed in any::<u64>()) {
        let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");

        let (ct_a, _) = kp.public_key()
            .try_encapsulate()
            .expect("encap a should succeed");
        let (ct_b, _) = kp.public_key()
            .try_encapsulate()
            .expect("encap b should succeed");

        proptest::prop_assert_ne!(
            ct_a.to_bytes(),
            ct_b.to_bytes(),
            "two encap calls under same public key must produce distinct ciphertexts",
        );
    }

    /// Wire-format serialization is a perfect round-trip — `to_bytes`
    /// followed by `from_bytes` yields a public key that decap'd-
    /// against ciphertexts produces the same shared secret.
    #[test]
    fn wire_format_round_trip(_seed in any::<u64>()) {
        let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");

        let pk_bytes = kp.public_key().to_bytes();
        let recovered_pk = HybridKemPublicKey::from_bytes(&pk_bytes);

        let (ciphertext, encap_secret) = recovered_pk
            .try_encapsulate()
            .expect("encap against recovered pk should succeed");

        let ct_bytes = ciphertext.to_bytes();
        let recovered_ct = HybridKemCiphertext::from_bytes(&ct_bytes);

        let decap_secret = kp.private_key()
            .try_decapsulate(&recovered_ct)
            .expect("decap recovered ct should succeed");

        proptest::prop_assert_eq!(
            encap_secret.expose_secret(),
            decap_secret.expose_secret(),
            "wire-format round-trip must preserve KEM correctness",
        );
    }
}
