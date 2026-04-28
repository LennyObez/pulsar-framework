//! Iteration tests for `pulsar_kernel::crypto::hybrid_sig`.
//!
//! Hybrid Ed25519+ML-DSA-65 signature. Each test repeats its core
//! invariant check across 16 randomly-generated CSPRNG-driven
//! keypairs / sign calls — same coverage as a proptest property but
//! without the single-input shrinking machinery (which would have
//! nothing to shrink in this test surface anyway, since the wrapper
//! does not yet expose seed-driven keypair / signing APIs for the
//! hybrid). Phase 1.1.D's hybrid-vector pass will replace these
//! with seed-driven proptest properties.
//!
//! Properties exercised:
//!
//! - Sign/verify round-trip across CSPRNG-driven keypairs
//! - Distinct keypairs yield distinct verification keys (probabilistic)
//! - Distinct sign calls yield distinct signatures under same key
//!   (because ML-DSA's hedged signing draws fresh randomness per
//!   call; Ed25519 is deterministic)
//! - Wire-format `to_bytes` / `from_bytes` is a perfect round-trip

#![allow(clippy::expect_used, clippy::unwrap_used)]

use pulsar_kernel::crypto::hybrid_sig::{HybridSigKeyPair, HybridSigPublicKey, HybridSigSignature};

/// Number of CSPRNG-driven iterations per test.
///
/// Hybrid keypair gen + sign + verify cost ~500 µs total (dominated
/// by ML-DSA-65). Per-test ≈ 500 µs × 16 ≈ 8 ms.
const ITERATIONS: usize = 16;

/// Sign/verify round-trip recovers `Ok(())`.
#[test]
fn sign_verify_round_trip() {
    for i in 0..ITERATIONS {
        let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
        let message = format!("iteration-{i}").into_bytes();
        let signature = kp
            .private_key()
            .try_sign(&message, b"")
            .expect("sign should succeed");
        kp.public_key()
            .verify(&message, b"", &signature)
            .expect("authentic signature must verify");
    }
}

/// Distinct hybrid signature keypairs yield distinct verification
/// keys (probabilistic). For two fresh CSPRNG-driven keypairs the
/// chance of identical Ed25519 OR ML-DSA-65 verification keys is
/// bounded by 2⁻²⁵⁶ + 2⁻¹⁵⁰⁰⁰ish, effectively zero.
#[test]
fn distinct_keypairs_yield_distinct_verification_keys() {
    for _ in 0..ITERATIONS {
        let kp_a = HybridSigKeyPair::try_generate().expect("kp a should succeed");
        let kp_b = HybridSigKeyPair::try_generate().expect("kp b should succeed");

        assert_ne!(
            kp_a.public_key().to_bytes(),
            kp_b.public_key().to_bytes(),
            "two fresh hybrid keypairs must yield distinct verification keys",
        );
    }
}

/// Distinct sign calls under the same signing key yield distinct
/// signatures. Ed25519 is deterministic per RFC 8032 § 5.1.6 so its
/// share is identical across calls, but ML-DSA-65 is randomised by
/// default per FIPS 204 § 5.4 (hedged mode draws fresh `rnd` per
/// call), so the ML-DSA share differs — and therefore the
/// concatenated wire-format signature differs.
#[test]
fn distinct_sign_calls_yield_distinct_signatures() {
    let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");
    let message = b"deterministic-vs-hedged signing test";

    for _ in 0..ITERATIONS {
        let sig_a = kp
            .private_key()
            .try_sign(message, b"")
            .expect("sign a should succeed");
        let sig_b = kp
            .private_key()
            .try_sign(message, b"")
            .expect("sign b should succeed");

        assert_ne!(
            sig_a.to_bytes(),
            sig_b.to_bytes(),
            "two sign calls under same key must produce distinct signatures \
             (ML-DSA-65's randomised signing changes per call)",
        );

        // Both signatures must verify under the same public key.
        kp.public_key()
            .verify(message, b"", &sig_a)
            .expect("sig a must verify");
        kp.public_key()
            .verify(message, b"", &sig_b)
            .expect("sig b must verify");
    }
}

/// Wire-format serialisation is a perfect round-trip.
#[test]
fn wire_format_round_trip() {
    for _ in 0..ITERATIONS {
        let kp = HybridSigKeyPair::try_generate().expect("keypair should succeed");

        let vk_bytes = kp.public_key().to_bytes();
        let recovered_vk = HybridSigPublicKey::from_bytes(&vk_bytes);

        let signature = kp
            .private_key()
            .try_sign(b"wire-format test", b"")
            .expect("sign should succeed");
        let sig_bytes = signature.to_bytes();
        let recovered_sig = HybridSigSignature::from_bytes(&sig_bytes);

        recovered_vk
            .verify(b"wire-format test", b"", &recovered_sig)
            .expect("wire-format-recovered sig must verify under wire-format-recovered vk");
    }
}
