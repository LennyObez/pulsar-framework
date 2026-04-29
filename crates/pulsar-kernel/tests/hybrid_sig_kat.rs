//! Known-answer + integration tests for `pulsar_kernel::crypto::hybrid_sig`.
//!
//! Hybrid Ed25519+ML-DSA-65 signature per Decision 2.58.
//! Cryptographic correctness of the constituent primitives is
//! asserted by the upstream test suites (HACL\* for Ed25519, libcrux
//! for ML-DSA-65); these wrapper-level tests focus on the hybrid
//! orchestration:
//!
//! - Sizes match the IETF hybrid-sig convention (1984 / 4064 / 3373)
//! - Sign/verify round-trip succeeds for any (message, context)
//! - Wire-format serialisation round-trip preserves both halves
//! - BOTH-must-pass semantics: tampered Ed25519 share fails verify
//! - BOTH-must-pass semantics: tampered ML-DSA share fails verify
//! - FIPS 204 § 5.2 + Ed25519 prefix: context mismatch fails verify
//! - Cross-key rejection: signatures from key A don't verify under key B
//! - Oversize context (> 255 bytes) → `InputTooLong`
//! - Hybrid signatures have HYBRID_LABEL domain separation against
//!   bare Ed25519 — a hybrid sig's Ed25519 share does NOT verify
//!   against the same message under a bare Ed25519 verifier

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::hybrid_sig::{
    HybridSigKeyPair, HybridSigPublicKey, HybridSigSignature, sizes,
};

/// Sizes match the hybrid Ed25519+ML-DSA-65 convention.
#[test]
fn hybrid_sig_sizes_match_convention() {
    assert_eq!(sizes::VERIFICATION_KEY_LEN, 1984);
    assert_eq!(sizes::SIGNING_KEY_LEN, 4064);
    assert_eq!(sizes::SIGNATURE_LEN, 3373);
    assert_eq!(sizes::MAX_CONTEXT_LEN, 255);
    assert_eq!(sizes::ED25519_VK_LEN, 32);
    assert_eq!(sizes::MLDSA_VK_LEN, 1952);
    assert_eq!(sizes::ED25519_SIG_LEN, 64);
    assert_eq!(sizes::MLDSA_SIG_LEN, 3309);

    assert_eq!(HybridSigPublicKey::LEN, 1984);
    assert_eq!(HybridSigSignature::LEN, 3373);
}

/// Sign/verify round-trip — the verifier accepts an authentic hybrid
/// signature produced by the matching signing key.
#[test]
fn hybrid_sig_sign_verify_round_trip() {
    let kp = HybridSigKeyPair::try_generate().expect("hybrid keypair should succeed");

    let message = b"Pulsar Phase 1.1.C.4 hybrid signature round-trip";
    let context = b"";
    let signature = kp
        .private_key()
        .try_sign(message, context)
        .expect("hybrid sign should succeed");

    kp.public_key()
        .verify(message, context, &signature)
        .expect("authentic hybrid signature must verify");
}

/// Sign/verify round-trip with a non-empty context exercises the
/// FIPS 204 § 5.2 ML-DSA context binding AND the Ed25519 prefix
/// transformation.
#[test]
fn hybrid_sig_sign_verify_round_trip_with_context() {
    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");

    let message = b"context-binding test";
    let context = b"protocol-A";
    let signature = kp
        .private_key()
        .try_sign(message, context)
        .expect("sign should succeed");

    kp.public_key()
        .verify(message, context, &signature)
        .expect("authentic signature with context must verify");
}

/// Verification-key wire format round-trip — `to_bytes` followed by
/// `from_bytes` recovers the same verification key (byte-equal in
/// both constituent halves).
#[test]
fn hybrid_sig_verification_key_serialization_round_trip() {
    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let original_vk = kp.public_key().clone();

    let serialized = original_vk.to_bytes();
    assert_eq!(serialized.len(), 1984);
    let recovered = HybridSigPublicKey::from_bytes(&serialized);

    assert_eq!(
        original_vk.classical().as_bytes(),
        recovered.classical().as_bytes(),
        "Ed25519 share must round-trip identically",
    );
    assert_eq!(
        original_vk.pqc().as_bytes(),
        recovered.pqc().as_bytes(),
        "ML-DSA share must round-trip identically",
    );
}

/// Signature wire format round-trip — recovered signature verifies
/// the same as the original.
#[test]
fn hybrid_sig_signature_serialization_round_trip() {
    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let message = b"signature serialization round-trip";
    let signature = kp
        .private_key()
        .try_sign(message, b"")
        .expect("sign should succeed");

    let serialized = signature.to_bytes();
    assert_eq!(serialized.len(), 3373);
    let recovered = HybridSigSignature::from_bytes(&serialized);

    // Both signatures should verify under the same verification key.
    kp.public_key()
        .verify(message, b"", &signature)
        .expect("original should verify");
    kp.public_key()
        .verify(message, b"", &recovered)
        .expect("wire-format-recovered should verify");
}

/// BOTH-must-pass semantics — tampering the Ed25519 share of the
/// signature causes verification to fail with `SignatureVerifyFailed`.
/// The ML-DSA share is left intact.
#[test]
fn hybrid_sig_verify_rejects_tampered_ed25519_share() {
    use pulsar_kernel::error::Error;

    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let message = b"Ed25519-tamper test";
    let signature = kp
        .private_key()
        .try_sign(message, b"")
        .expect("sign should succeed");

    // Flip a bit in the Ed25519 share (offset 0 of the wire format).
    let mut tampered_bytes = signature.to_bytes();
    tampered_bytes[0] ^= 0x01;
    let tampered = HybridSigSignature::from_bytes(&tampered_bytes);

    match kp.public_key().verify(message, b"", &tampered) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for tampered Ed25519 share; got {other:?}"),
    }
}

/// BOTH-must-pass semantics — tampering the ML-DSA share of the
/// signature causes verification to fail with `SignatureVerifyFailed`.
/// The Ed25519 share is left intact (still individually valid),
/// but the BOTH-must-pass invariant rejects the hybrid signature.
#[test]
fn hybrid_sig_verify_rejects_tampered_mldsa_share() {
    use pulsar_kernel::error::Error;

    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let message = b"ML-DSA-tamper test";
    let signature = kp
        .private_key()
        .try_sign(message, b"")
        .expect("sign should succeed");

    // Flip a bit in the ML-DSA share (offset 64 of the wire format).
    let mut tampered_bytes = signature.to_bytes();
    tampered_bytes[sizes::MLDSA_SIG_OFFSET] ^= 0x01;
    let tampered = HybridSigSignature::from_bytes(&tampered_bytes);

    match kp.public_key().verify(message, b"", &tampered) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for tampered ML-DSA share; got {other:?}"),
    }
}

/// Verifying a tampered message under an authentic signature fails.
#[test]
fn hybrid_sig_verify_rejects_tampered_message() {
    use pulsar_kernel::error::Error;

    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let signature = kp
        .private_key()
        .try_sign(b"original message", b"")
        .expect("sign should succeed");

    match kp.public_key().verify(b"tampered message", b"", &signature) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for tampered message; got {other:?}"),
    }
}

/// Verifying with a different context than was used at signing time
/// fails. This exercises BOTH the ML-DSA § 5.2 native context
/// binding AND the Ed25519 prefix transformation.
#[test]
fn hybrid_sig_verify_rejects_wrong_context() {
    use pulsar_kernel::error::Error;

    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let message = b"context-mismatch test";
    let signature = kp
        .private_key()
        .try_sign(message, b"protocol-A")
        .expect("sign should succeed");

    match kp.public_key().verify(message, b"protocol-B", &signature) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for context mismatch; got {other:?}"),
    }
}

/// Verifying under an unrelated verification key fails.
#[test]
fn hybrid_sig_verify_rejects_wrong_verification_key() {
    use pulsar_kernel::error::Error;

    let kp_a = HybridSigKeyPair::try_generate().expect("kp a should succeed");
    let kp_b = HybridSigKeyPair::try_generate().expect("kp b should succeed");

    let message = b"cross-key test";
    let signature_a = kp_a
        .private_key()
        .try_sign(message, b"")
        .expect("sign a should succeed");

    match kp_b.public_key().verify(message, b"", &signature_a) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for wrong key; got {other:?}"),
    }
}

/// Signing with an oversize context (> 255 bytes) is rejected.
#[test]
fn hybrid_sig_sign_rejects_oversize_context() {
    use pulsar_kernel::error::Error;

    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let oversize = vec![0_u8; 256];
    match kp.private_key().try_sign(b"any message", &oversize) {
        Err(Error::InputTooLong {
            actual: 256,
            max: 255,
        }) => {} // expected
        other => panic!("expected InputTooLong; got {other:?}"),
    }
}

/// Verifying with an oversize context (> 255 bytes) is rejected.
#[test]
fn hybrid_sig_verify_rejects_oversize_context() {
    use pulsar_kernel::error::Error;

    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let signature = kp
        .private_key()
        .try_sign(b"msg", b"")
        .expect("sign should succeed");

    let oversize = vec![0_u8; 256];
    match kp.public_key().verify(b"msg", &oversize, &signature) {
        Err(Error::InputTooLong {
            actual: 256,
            max: 255,
        }) => {} // expected
        other => panic!("expected InputTooLong; got {other:?}"),
    }
}

/// Domain-separation invariant: a hybrid signature's Ed25519 share
/// does NOT verify under a bare Ed25519 verifier on the raw message
/// bytes. The hybrid scheme prefixes `HYBRID_LABEL ||
/// context_len_byte || context` to the Ed25519 input, so a hybrid
/// Ed25519 signature is structurally distinct from a bare Ed25519
/// signature using the same signing key.
///
/// This invariant is important for the regulated-domain audit
/// posture — it guarantees that a hybrid signature cannot be
/// "downgraded" to a classical-only signature by stripping the
/// PQC half and exposing the Ed25519 share.
#[test]
fn hybrid_sig_ed25519_share_not_valid_for_raw_message() {
    use pulsar_kernel::error::Error;

    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let message = b"domain-separation test";
    let signature = kp
        .private_key()
        .try_sign(message, b"")
        .expect("sign should succeed");

    // Attempt to verify the hybrid's Ed25519 share against the RAW
    // message under the bare Ed25519 verifier (i.e., without
    // applying the hybrid prefix transformation). This must fail —
    // the hybrid scheme signs the prefix-transformed input, so the
    // raw-message verification cannot recover the same digest.
    let bare_verifier = kp.public_key().classical();
    match bare_verifier.verify(message, signature.classical()) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!(
            "expected SignatureVerifyFailed (hybrid Ed25519 share must NOT verify against raw \
             message under bare Ed25519); got {other:?}",
        ),
    }
}

/// `Debug` redaction on the hybrid private key.
#[test]
fn hybrid_sig_debug_redacts_private_key() {
    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");

    let private_debug = format!("{:?}", kp.private_key());
    assert!(private_debug.contains("HybridSigPrivateKey"));
    assert!(
        private_debug.contains(".."),
        "hybrid private-key Debug must use finish_non_exhaustive",
    );

    let public_debug = format!("{:?}", kp.public_key());
    assert!(public_debug.contains("HybridSigPublicKey"));
}
