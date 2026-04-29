//! Hybrid post-quantum + classical digital signature.
//!
//! Per Decision 2.58 hybrid PQC posture from Sprint 1.1, every
//! signature scheme that anchors a long-term commitment runs
//! **Ed25519 + ML-DSA-65 in parallel** and verifies REQUIRE BOTH
//! to pass. This sub-phase ships the safe Rust wrapper that
//! orchestrates the two constituent primitives (security-reviewed
//! in Phase 1.1.B.3 for Ed25519 and Phase 1.1.C.2 for ML-DSA-65).
//!
//! # Why hybrid signatures
//!
//! - **Quantum resistance** — ML-DSA-65 is FIPS 204's NIST-PQC
//!   signature scheme. A future quantum adversary cannot forge the
//!   ML-DSA half.
//! - **Classical fallback** — Ed25519 is RFC 8032's elliptic-curve
//!   digital-signature scheme with two decades of cryptanalytic
//!   scrutiny. If a structural attack is discovered against
//!   ML-DSA-65, the Ed25519 half still provides 128-bit-equivalent
//!   security.
//! - **Defence in depth via independent primitives** — the two
//!   schemes share neither hardness assumption nor implementation
//!   surface. A break against one does not propagate to the other.
//! - **Conservative posture** — preserves classical-only verifiability
//!   for legacy clients while gaining PQC protection. A bare Ed25519
//!   verifier holding only the classical half can validate the
//!   Ed25519 share independently (after applying the documented
//!   prefix transformation).
//!
//! # Combining strategy: parallel + AND-verify
//!
//! `HybridSigPrivateKey::try_sign(message, context)` produces:
//!
//! - `sig_ed25519 = Ed25519::sign(sk_ed25519, prefix(context, message))`
//! - `sig_mldsa   = ML-DSA-65::sign(sk_mldsa, message, context)`
//!
//! `HybridSigPublicKey::verify(message, context, &signature)`
//! returns `Ok(())` iff BOTH:
//!
//! - `Ed25519::verify(vk_ed25519, prefix(context, message), sig.classical)` → `Ok`
//! - `ML-DSA-65::verify(vk_mldsa, message, context, sig.pqc)` → `Ok`
//!
//! Either verification failure collapses to a single
//! [`Error::SignatureVerifyFailed`] — no information leaked about
//! which sub-step (or which underlying scheme) failed, mirroring
//! the [`Ed25519PublicKey::verify`] and
//! [`MlDsa65VerificationKey::verify`] postures.
//!
//! # Context binding via Ed25519 prefix transformation
//!
//! Ed25519 (RFC 8032) does not have a native context parameter.
//! ML-DSA-65 (FIPS 204 § 5.2) does. To bind the same context to
//! both halves of the hybrid signature, the wrapper transforms the
//! Ed25519 input to:
//!
//! ```text
//! ed25519_input = HYBRID_LABEL || context_len_byte || context || message
//!               = "pulsar-hybrid-sig-ed25519-mldsa65-v1" || u8(|ctx|) || ctx || M
//! ```
//!
//! The single-byte length prefix (`context_len_byte`) ensures the
//! Ed25519 input is unambiguously parseable: a context-empty
//! signature over message `M` is distinct from a context-of-length-
//! ≤-255 signature over the same `M`. The HYBRID_LABEL provides
//! domain separation against bare Ed25519 signatures using the same
//! signing key — a hybrid Ed25519 sig will NOT verify under a bare
//! Ed25519 verifier on the raw message bytes.
//!
//! ML-DSA-65 receives the original `(message, context)` pair and
//! applies its native FIPS 204 § 5.2 context binding internally.
//!
//! # Wire format
//!
//! - **Verification key** = `vk_ed25519 || vk_mldsa`     (32  + 1 952 = 1 984 bytes)
//! - **Signing key** = stored separately as `(Ed25519PrivateKey, MlDsa65SigningKey)`
//!   — the wire format for serialisation is `sk_ed25519 || sk_mldsa` (32 + 4 032 = 4 064 bytes)
//! - **Signature** = `sig_ed25519 || sig_mldsa`           (64  + 3 309 = 3 373 bytes)
//!
//! These layouts mirror the IETF X25519MLKEM768 / hybrid-sig
//! convention (classical share first, PQC share second).
//!
//! # Context length bound
//!
//! `context.len()` must be ≤ 255 — this is the FIPS 204 § 5.2 bound
//! AND the maximum value representable in the single-byte length
//! prefix used by the Ed25519 input transformation. Excess-length
//! contexts are rejected as [`Error::InputTooLong`] at both sign
//! and verify entry points.
//!
//! # Key-material handling
//!
//! Following the canonical pattern from Phase 1.1.C.3 (hybrid KEM):
//! the hybrid signing key holds an [`Ed25519PrivateKey`]
//! (SecretBox-wrapped 32-byte seed) alongside an
//! [`MlDsa65SigningKey`] (SecretBox-wrapped 4 032-byte private
//! key). Both inherit the canonical Pulsar zeroize-on-drop posture
//! from earlier phases — no new private-key storage introduced.

use crate::crypto::ml_dsa::{
    MlDsa65KeyPair, MlDsa65Signature, MlDsa65SigningKey, MlDsa65VerificationKey,
};
use crate::crypto::signature::{Ed25519PrivateKey, Ed25519PublicKey, Ed25519Signature};
use crate::error::{Error, Result};
use zeroize::Zeroizing;

/// Domain-separation label prefixed to the Ed25519 input. Versioned
/// so a future transformation change can revise the label without
/// colliding with stored signatures derived from the v1 scheme.
const HYBRID_SIG_LABEL: &[u8] = b"pulsar-hybrid-sig-ed25519-mldsa65-v1";

/// Hybrid Ed25519 + ML-DSA-65 fixed sizes — re-exposed as public
/// constants for caller-side allocation sizing.
pub mod sizes {
    /// Hybrid verification-key length in bytes: `32 + 1952 = 1984`.
    pub const VERIFICATION_KEY_LEN: usize = 32 + 1952;
    /// Hybrid signing-key wire-format length in bytes: `32 + 4032 = 4064`.
    pub const SIGNING_KEY_LEN: usize = 32 + 4032;
    /// Hybrid signature length in bytes: `64 + 3309 = 3373`.
    pub const SIGNATURE_LEN: usize = 64 + 3309;
    /// Maximum context-string length (FIPS 204 § 5.2 + single-byte
    /// length prefix in the Ed25519 input transformation).
    pub const MAX_CONTEXT_LEN: usize = 255;
    /// Ed25519 share length within the verification key.
    pub const ED25519_VK_LEN: usize = 32;
    /// ML-DSA-65 share offset within the verification key.
    pub const MLDSA_VK_OFFSET: usize = 32;
    /// ML-DSA-65 share length within the verification key.
    pub const MLDSA_VK_LEN: usize = 1952;
    /// Ed25519 share length within the signature.
    pub const ED25519_SIG_LEN: usize = 64;
    /// ML-DSA-65 share offset within the signature (`[64..3373]`).
    pub const MLDSA_SIG_OFFSET: usize = 64;
    /// ML-DSA-65 share length within the signature.
    pub const MLDSA_SIG_LEN: usize = 3309;
}

/// Build the Ed25519 input bytes from `(context, message)` per the
/// hybrid scheme's prefix transformation:
///
/// ```text
/// HYBRID_LABEL || context_len_byte || context || message
/// ```
///
/// The result is a heap-allocated `Zeroizing<Vec<u8>>` so the
/// transformed input is wiped on drop — though the inputs `context`
/// and `message` are themselves public per the digital-signature
/// threat model, the buffer is held briefly in memory and the
/// zeroize-on-drop covers any incidental retention concerns.
fn ed25519_input(context: &[u8], message: &[u8]) -> Zeroizing<Vec<u8>> {
    debug_assert!(context.len() <= sizes::MAX_CONTEXT_LEN);
    let mut buf = Vec::with_capacity(HYBRID_SIG_LABEL.len() + 1 + context.len() + message.len());
    buf.extend_from_slice(HYBRID_SIG_LABEL);
    // Length-prefix the context (single byte) so the Ed25519 input is
    // unambiguously parseable. The cast is sound because the caller
    // has already verified `context.len() <= 255`.
    buf.push(u8::try_from(context.len()).unwrap_or(255));
    buf.extend_from_slice(context);
    buf.extend_from_slice(message);
    Zeroizing::new(buf)
}

/// Hybrid (classical + post-quantum) verification (public) key.
///
/// Holds an [`Ed25519PublicKey`] alongside an [`MlDsa65VerificationKey`].
/// Verification runs both schemes in parallel and requires BOTH to
/// pass per Decision 2.58.
#[derive(Clone)]
pub struct HybridSigPublicKey {
    classical: Ed25519PublicKey,
    pqc: MlDsa65VerificationKey,
}

impl core::fmt::Debug for HybridSigPublicKey {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridSigPublicKey")
            .field("classical", &self.classical)
            .field("pqc", &self.pqc)
            .finish()
    }
}

impl HybridSigPublicKey {
    /// Hybrid verification-key length in bytes (`32 + 1952 = 1984`).
    pub const LEN: usize = sizes::VERIFICATION_KEY_LEN;

    /// Construct from a 1 984-byte buffer in `vk_ed25519 || vk_mldsa`
    /// wire format.
    #[must_use]
    pub fn from_bytes(bytes: &[u8; Self::LEN]) -> Self {
        let mut ed_bytes = [0_u8; sizes::ED25519_VK_LEN];
        ed_bytes.copy_from_slice(&bytes[..sizes::ED25519_VK_LEN]);
        let classical = Ed25519PublicKey::from_bytes(ed_bytes);

        let mut mldsa_bytes = [0_u8; sizes::MLDSA_VK_LEN];
        mldsa_bytes.copy_from_slice(&bytes[sizes::MLDSA_VK_OFFSET..]);
        let pqc = MlDsa65VerificationKey::from_bytes(mldsa_bytes);

        Self { classical, pqc }
    }

    /// Construct from individual Ed25519 + ML-DSA-65 verification
    /// keys.
    #[must_use]
    pub const fn from_parts(classical: Ed25519PublicKey, pqc: MlDsa65VerificationKey) -> Self {
        Self { classical, pqc }
    }

    /// Return the Ed25519 share.
    #[must_use]
    pub const fn classical(&self) -> &Ed25519PublicKey {
        &self.classical
    }

    /// Return the ML-DSA-65 share.
    #[must_use]
    pub const fn pqc(&self) -> &MlDsa65VerificationKey {
        &self.pqc
    }

    /// Serialize the hybrid verification key to wire format:
    /// `vk_ed25519 || vk_mldsa`.
    #[must_use]
    pub fn to_bytes(&self) -> [u8; Self::LEN] {
        let mut out = [0_u8; Self::LEN];
        out[..sizes::ED25519_VK_LEN].copy_from_slice(self.classical.as_bytes());
        out[sizes::MLDSA_VK_OFFSET..].copy_from_slice(self.pqc.as_bytes());
        out
    }

    /// Verify a hybrid signature over `message` with optional domain-
    /// separation `context`. Returns `Ok(())` iff BOTH the Ed25519
    /// share AND the ML-DSA-65 share verify under their respective
    /// keys.
    ///
    /// Either verification failure collapses to a single
    /// [`Error::SignatureVerifyFailed`] — no information leaked
    /// about which sub-step failed.
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `context.len() > 255`.
    /// - [`Error::SignatureVerifyFailed`] — either constituent
    ///   signature is invalid for the (verification key, message,
    ///   context) triple.
    pub fn verify(
        &self,
        message: &[u8],
        context: &[u8],
        signature: &HybridSigSignature,
    ) -> Result<()> {
        if context.len() > sizes::MAX_CONTEXT_LEN {
            return Err(Error::InputTooLong {
                actual: context.len(),
                max: sizes::MAX_CONTEXT_LEN,
            });
        }

        // 1. Ed25519 verify over the prefix-transformed message.
        let ed_input = ed25519_input(context, message);
        self.classical
            .verify(&ed_input, &signature.classical)
            .map_err(|_| Error::SignatureVerifyFailed)?;

        // 2. ML-DSA-65 verify over the original message + context.
        self.pqc
            .verify(message, context, &signature.pqc)
            .map_err(|_| Error::SignatureVerifyFailed)?;

        Ok(())
    }
}

/// Hybrid (classical + post-quantum) signing (private) key.
///
/// Holds an [`Ed25519PrivateKey`] alongside an [`MlDsa65SigningKey`].
/// Both inherit zeroize-on-drop semantics from their constituent
/// SecretBox-backed storage; no new private-key storage introduced
/// at the hybrid layer.
pub struct HybridSigPrivateKey {
    classical: Ed25519PrivateKey,
    pqc: MlDsa65SigningKey,
}

impl core::fmt::Debug for HybridSigPrivateKey {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridSigPrivateKey")
            .field("classical", &self.classical)
            .field("pqc", &self.pqc)
            .finish_non_exhaustive()
    }
}

impl HybridSigPrivateKey {
    /// Construct from individual Ed25519 + ML-DSA-65 signing keys.
    #[must_use]
    pub const fn from_parts(classical: Ed25519PrivateKey, pqc: MlDsa65SigningKey) -> Self {
        Self { classical, pqc }
    }

    /// Return the Ed25519 share.
    #[must_use]
    pub const fn classical(&self) -> &Ed25519PrivateKey {
        &self.classical
    }

    /// Return the ML-DSA-65 share.
    #[must_use]
    pub const fn pqc(&self) -> &MlDsa65SigningKey {
        &self.pqc
    }

    /// Sign `message` with optional domain-separation `context` under
    /// this hybrid signing key. Produces an [`HybridSigSignature`]
    /// containing both the Ed25519 share (over the prefix-
    /// transformed message) and the ML-DSA-65 share (over the
    /// original message + context).
    ///
    /// ML-DSA-65 signing draws 32 bytes of CSPRNG randomness for the
    /// FIPS 204 § 5.4 hedged signing mode. Ed25519 is fully
    /// deterministic per RFC 8032 § 5.1.6 — no CSPRNG draw.
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `context.len() > 255`.
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during ML-DSA-65 randomness generation.
    /// - [`Error::SigningFailed`] — ML-DSA-65's rejection-sampling
    ///   loop exceeded the maximum number of attempts (vanishingly
    ///   rare; treat as fatal-but-transient).
    pub fn try_sign(&self, message: &[u8], context: &[u8]) -> Result<HybridSigSignature> {
        if context.len() > sizes::MAX_CONTEXT_LEN {
            return Err(Error::InputTooLong {
                actual: context.len(),
                max: sizes::MAX_CONTEXT_LEN,
            });
        }

        // 1. Ed25519 sign over the prefix-transformed message.
        let ed_input = ed25519_input(context, message);
        let ed_signature = self.classical.sign(&ed_input)?;

        // 2. ML-DSA-65 sign over the original message + context.
        let mldsa_signature = self.pqc.try_sign(message, context)?;

        Ok(HybridSigSignature {
            classical: ed_signature,
            pqc: mldsa_signature,
        })
    }
}

/// Hybrid signature (3 373 bytes — `sig_ed25519 || sig_mldsa`).
#[derive(Clone)]
pub struct HybridSigSignature {
    classical: Ed25519Signature,
    pqc: MlDsa65Signature,
}

impl core::fmt::Debug for HybridSigSignature {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridSigSignature")
            .field("classical", &self.classical)
            .field("pqc", &self.pqc)
            .finish()
    }
}

impl HybridSigSignature {
    /// Hybrid signature length in bytes (`64 + 3309 = 3373`).
    pub const LEN: usize = sizes::SIGNATURE_LEN;

    /// Construct from a 3 373-byte buffer in `sig_ed25519 ||
    /// sig_mldsa` wire format.
    #[must_use]
    pub fn from_bytes(bytes: &[u8; Self::LEN]) -> Self {
        let mut ed_bytes = [0_u8; sizes::ED25519_SIG_LEN];
        ed_bytes.copy_from_slice(&bytes[..sizes::ED25519_SIG_LEN]);
        let classical = Ed25519Signature::from_bytes(ed_bytes);

        let mut mldsa_bytes = [0_u8; sizes::MLDSA_SIG_LEN];
        mldsa_bytes.copy_from_slice(&bytes[sizes::MLDSA_SIG_OFFSET..]);
        let pqc = MlDsa65Signature::from_bytes(mldsa_bytes);

        Self { classical, pqc }
    }

    /// Construct from individual Ed25519 + ML-DSA-65 signatures.
    #[must_use]
    pub const fn from_parts(classical: Ed25519Signature, pqc: MlDsa65Signature) -> Self {
        Self { classical, pqc }
    }

    /// Return the Ed25519 share.
    #[must_use]
    pub const fn classical(&self) -> &Ed25519Signature {
        &self.classical
    }

    /// Return the ML-DSA-65 share.
    #[must_use]
    pub const fn pqc(&self) -> &MlDsa65Signature {
        &self.pqc
    }

    /// Serialize the hybrid signature to wire format: `sig_ed25519
    /// || sig_mldsa`.
    #[must_use]
    pub fn to_bytes(&self) -> [u8; Self::LEN] {
        let mut out = [0_u8; Self::LEN];
        out[..sizes::ED25519_SIG_LEN].copy_from_slice(self.classical.as_bytes());
        out[sizes::MLDSA_SIG_OFFSET..].copy_from_slice(self.pqc.as_bytes());
        out
    }
}

/// Hybrid signature keypair — bundles an Ed25519 + ML-DSA-65
/// keypair pair produced by a single keypair-generation flow.
pub struct HybridSigKeyPair {
    public_key: HybridSigPublicKey,
    private_key: HybridSigPrivateKey,
}

impl core::fmt::Debug for HybridSigKeyPair {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridSigKeyPair")
            .field("public_key", &self.public_key)
            .field("private_key", &self.private_key)
            .finish()
    }
}

impl HybridSigKeyPair {
    /// Generate a fresh hybrid signature keypair using the OS CSPRNG.
    ///
    /// Independently generates an Ed25519 keypair (32-byte random
    /// seed via [`Ed25519PrivateKey::try_generate`]) and an
    /// ML-DSA-65 keypair (32-byte random seed via
    /// [`MlDsa65KeyPair::try_generate`]). The two keypairs share no
    /// entropy — keypair-isolation invariant from Decision 2.58.
    ///
    /// # Errors
    ///
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during either constituent keypair generation.
    pub fn try_generate() -> Result<Self> {
        let classical_secret = Ed25519PrivateKey::try_generate()?;
        let classical_public = classical_secret.public_key();

        let mldsa_keypair = MlDsa65KeyPair::try_generate()?;
        let (mldsa_verification, mldsa_signing) = mldsa_keypair.into_parts();

        Ok(Self {
            public_key: HybridSigPublicKey {
                classical: classical_public,
                pqc: mldsa_verification,
            },
            private_key: HybridSigPrivateKey {
                classical: classical_secret,
                pqc: mldsa_signing,
            },
        })
    }

    /// Return a reference to the hybrid verification key.
    #[must_use]
    pub const fn public_key(&self) -> &HybridSigPublicKey {
        &self.public_key
    }

    /// Return a reference to the hybrid signing key.
    #[must_use]
    pub const fn private_key(&self) -> &HybridSigPrivateKey {
        &self.private_key
    }

    /// Decompose the keypair into its constituent verification +
    /// signing keys, transferring ownership.
    #[must_use]
    pub fn into_parts(self) -> (HybridSigPublicKey, HybridSigPrivateKey) {
        (self.public_key, self.private_key)
    }
}
