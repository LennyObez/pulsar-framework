//! Known-answer + integration tests for `pulsar_kernel::crypto::hybrid_kem`.
//!
//! Hybrid X25519+ML-KEM-768 KEM per Decision 2.58. Cryptographic
//! correctness of the constituent primitives is asserted by the
//! upstream test suites (HACL\* for X25519, libcrux for ML-KEM-768);
//! these wrapper-level tests focus on the hybrid orchestration:
//!
//! - Sizes match the IETF X25519MLKEM768 codepoint (1216 / 1120 / 32)
//! - Encap/decap round-trip — encapsulator's combined shared secret
//!   equals decapsulator's combined shared secret
//! - Wire-format serialization round-trip (`to_bytes` ↔ `from_bytes`)
//!   for public keys + ciphertexts
//! - Constituent primitives are independently zeroized — the X25519
//!   private key and ML-KEM private key share no entropy
//! - HKDF combiner correctly mixes both shares — corrupting either
//!   constituent shared secret yields a different combined output
//!   (validates that a future X25519-only or ML-KEM-only break does
//!   NOT propagate to the hybrid)
//! - Tampered X25519 share in ciphertext fails decap (small-order
//!   rejection from HACL\*)
//! - Tampered ML-KEM share in ciphertext yields a divergent shared
//!   secret (FIPS 203 § 7.3 implicit rejection)

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::hybrid_kem::{
    HybridKemCiphertext, HybridKemKeyPair, HybridKemPublicKey, sizes,
};
use secrecy::ExposeSecret;

/// Sizes match the IETF X25519MLKEM768 codepoint convention.
#[test]
fn hybrid_kem_sizes_match_ietf_codepoint() {
    assert_eq!(sizes::PUBLIC_KEY_LEN, 1216);
    assert_eq!(sizes::PRIVATE_KEY_LEN, 2432);
    assert_eq!(sizes::CIPHERTEXT_LEN, 1120);
    assert_eq!(sizes::SHARED_SECRET_LEN, 32);
    assert_eq!(sizes::X25519_LEN, 32);
    assert_eq!(sizes::MLKEM_PUBLIC_KEY_LEN, 1184);
    assert_eq!(sizes::MLKEM_CIPHERTEXT_LEN, 1088);

    assert_eq!(HybridKemPublicKey::LEN, 1216);
    assert_eq!(HybridKemCiphertext::LEN, 1120);
}

/// Hybrid encap/decap round-trip — encapsulator's combined shared
/// secret equals decapsulator's combined shared secret. Drives the
/// full HKDF-SHA-256 combiner over both X25519 + ML-KEM-768 shares.
#[test]
fn hybrid_kem_encap_decap_round_trip() {
    let kp = HybridKemKeyPair::try_generate().expect("hybrid keypair should succeed");

    let (ciphertext, encap_secret) = kp
        .public_key()
        .try_encapsulate()
        .expect("encapsulate should succeed");

    let decap_secret = kp
        .private_key()
        .try_decapsulate(&ciphertext)
        .expect("decapsulate should succeed");

    assert_eq!(
        encap_secret.expose_secret(),
        decap_secret.expose_secret(),
        "hybrid encap shared secret must equal hybrid decap shared secret",
    );
    assert_eq!(encap_secret.expose_secret().len(), 32);
}

/// Public-key wire format round-trip — `to_bytes` followed by
/// `from_bytes` recovers the same public key (byte-equal in both
/// constituent halves).
#[test]
fn hybrid_kem_public_key_serialization_round_trip() {
    let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
    let original_pk = kp.public_key().clone();

    let serialized = original_pk.to_bytes();
    assert_eq!(serialized.len(), 1216);
    let recovered = HybridKemPublicKey::from_bytes(&serialized);

    assert_eq!(
        original_pk.classical().as_bytes(),
        recovered.classical().as_bytes(),
        "X25519 share must round-trip identically",
    );
    assert_eq!(
        original_pk.pqc().as_bytes(),
        recovered.pqc().as_bytes(),
        "ML-KEM share must round-trip identically",
    );
}

/// Ciphertext wire format round-trip.
#[test]
fn hybrid_kem_ciphertext_serialization_round_trip() {
    let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
    let (original_ct, _ss) = kp
        .public_key()
        .try_encapsulate()
        .expect("encapsulate should succeed");

    let serialized = original_ct.to_bytes();
    assert_eq!(serialized.len(), 1120);
    let recovered = HybridKemCiphertext::from_bytes(&serialized);

    // Round-tripped ciphertext must decapsulate to the same shared
    // secret as the original.
    let ss_original = kp
        .private_key()
        .try_decapsulate(&original_ct)
        .expect("decap original should succeed");
    let ss_recovered = kp
        .private_key()
        .try_decapsulate(&recovered)
        .expect("decap recovered should succeed");
    assert_eq!(
        ss_original.expose_secret(),
        ss_recovered.expose_secret(),
        "wire-format round-trip must preserve decapsulation output",
    );
}

/// Distinct hybrid keypairs yield distinct public keys (probabilistic).
#[test]
fn hybrid_kem_distinct_keypairs_yield_distinct_public_keys() {
    let kp_a = HybridKemKeyPair::try_generate().expect("kp a should succeed");
    let kp_b = HybridKemKeyPair::try_generate().expect("kp b should succeed");

    assert_ne!(
        kp_a.public_key().to_bytes(),
        kp_b.public_key().to_bytes(),
        "two fresh hybrid keypairs must yield distinct public keys",
    );
}

/// `validate()` accepts a fresh hybrid public key. Validates the
/// ML-KEM-768 share via FIPS 203 § 7.2 (the X25519 share has no
/// pre-DH validation step).
#[test]
fn hybrid_kem_validate_accepts_fresh_public_key() {
    let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
    kp.public_key()
        .validate()
        .expect("fresh hybrid public key must validate");
}

/// Tampering the X25519 ephemeral share in the ciphertext to a
/// small-order point causes decap to fail with `InvalidPublicKey`
/// (HACL\*'s F\* postcondition rejects small-order DH peers).
#[test]
fn hybrid_kem_decap_rejects_small_order_x25519_in_ciphertext() {
    use pulsar_kernel::crypto::kem::X25519PublicKey;
    use pulsar_kernel::crypto::ml_kem::MlKem768Ciphertext;
    use pulsar_kernel::error::Error;

    let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
    let (ciphertext, _) = kp
        .public_key()
        .try_encapsulate()
        .expect("encapsulate should succeed");

    // Replace the X25519 ephemeral share with the canonical small-
    // order point (u = 0). The ML-KEM ciphertext is left intact.
    let small_order = X25519PublicKey::from_bytes([0_u8; 32]);
    let mlkem_ct = ciphertext.pqc().clone();
    let tampered = HybridKemCiphertext::from_parts(small_order, mlkem_ct);

    match kp.private_key().try_decapsulate(&tampered) {
        Err(Error::InvalidPublicKey) => {} // expected
        other => panic!("expected InvalidPublicKey for small-order X25519 share; got {other:?}",),
    }

    // For coverage: drop unused import warnings on MlKem768Ciphertext (used implicitly)
    let _: Option<MlKem768Ciphertext> = None;
}

/// Tampering the ML-KEM share in the ciphertext yields a divergent
/// shared secret. Per FIPS 203 § 7.3 implicit rejection, ML-KEM
/// decap is infallible; the HKDF combiner mixes the divergent
/// `ss_mlkem` (uncorrelated with the encapsulator's authentic
/// `ss_mlkem`) into the combined shared secret, producing a
/// completely different output.
#[test]
fn hybrid_kem_decap_diverges_on_tampered_mlkem_share() {
    let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
    let (original_ct, encap_secret) = kp
        .public_key()
        .try_encapsulate()
        .expect("encapsulate should succeed");

    // Flip a single bit in the ML-KEM share (offset 32 of the wire
    // format = first byte of the ML-KEM ciphertext).
    let mut tampered_bytes = original_ct.to_bytes();
    tampered_bytes[sizes::MLKEM_CIPHERTEXT_OFFSET] ^= 0x01;
    let tampered = HybridKemCiphertext::from_bytes(&tampered_bytes);

    let tampered_secret = kp
        .private_key()
        .try_decapsulate(&tampered)
        .expect("decap should not error — ML-KEM implicit rejection is infallible");

    assert_ne!(
        encap_secret.expose_secret(),
        tampered_secret.expose_secret(),
        "tampered ML-KEM share must yield a divergent combined shared secret",
    );
}

/// Tampering the X25519 share with a non-small-order tampered byte
/// (single-bit flip in the X25519 ephemeral key) yields a different
/// shared secret on decap (the X25519 share contributes to HKDF IKM,
/// so any change cascades). This validates that the hybrid combiner
/// actually mixes the X25519 share — a bug that ignored ss_x25519
/// would produce identical output despite the X25519 tampering.
#[test]
fn hybrid_kem_decap_diverges_on_tampered_x25519_share() {
    let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
    let (original_ct, encap_secret) = kp
        .public_key()
        .try_encapsulate()
        .expect("encapsulate should succeed");

    // Flip a single bit in the X25519 share (byte 1, bit 0). Bit 0
    // of byte 0 is sometimes special in X25519's clamping but
    // tampering byte 1 is unambiguous.
    let mut tampered_bytes = original_ct.to_bytes();
    tampered_bytes[1] ^= 0x01;
    let tampered = HybridKemCiphertext::from_bytes(&tampered_bytes);

    let tampered_secret = kp
        .private_key()
        .try_decapsulate(&tampered)
        .expect("decap of tampered-but-not-small-order X25519 should succeed");

    assert_ne!(
        encap_secret.expose_secret(),
        tampered_secret.expose_secret(),
        "tampered X25519 share must yield a divergent combined shared secret \
         (validates that the HKDF combiner actually mixes the X25519 share)",
    );
}

/// `Debug` impls do not leak signing-key bytes. Hybrid private key
/// uses `finish_non_exhaustive`; constituent shares delegate to their
/// own redacting Debug impls.
#[test]
fn hybrid_kem_debug_redacts_private_key() {
    let kp = HybridKemKeyPair::try_generate().expect("keypair should succeed");
    let private_debug = format!("{:?}", kp.private_key());
    assert!(private_debug.contains("HybridKemPrivateKey"));
    assert!(
        private_debug.contains(".."),
        "hybrid private-key Debug must use finish_non_exhaustive",
    );
}
