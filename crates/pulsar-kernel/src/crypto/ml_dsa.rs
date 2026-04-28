//! ML-DSA (Module Lattice-based Digital Signature Algorithm) — safe
//! Rust wrappers over libcrux per Decision 2.60.
//!
//! Per FIPS 204 (final August 2024), ML-DSA is the NIST-standardised
//! post-quantum digital-signature scheme derived from CRYSTALS-Dilithium.
//! Pulsar ships the ML-DSA-65 parameter set (NIST security category 3
//! — equivalent to AES-192) per Decision 2.58 hybrid PQC posture, where
//! every signature scheme that anchors a long-term commitment runs
//! **Ed25519 + ML-DSA-65 in parallel** and verifies REQUIRE BOTH to
//! pass. The hybrid construction ships in Phase 1.1.C.3; this
//! sub-phase lands the bare ML-DSA-65 primitive that the hybrid
//! layers on top of.
//!
//! # Sourcing — libcrux Rust-native (Decision 2.60)
//!
//! ML-DSA-65 is sourced from `libcrux-ml-dsa 0.0.6` — Cryspen's
//! Rust-native verified extraction with `hax + F*` proofs of FIPS 204
//! conformance. Same Cryspen / hax / F\* verification provenance as
//! HACL\* (classical primitives) and `libcrux-ml-kem` (Phase 1.1.C.1
//! ML-KEM-768) — see Decision 2.60 for the unified provenance
//! argument.
//!
//! # API surface
//!
//! Three flows cover the typical use cases:
//!
//! - **Keypair generation** — `MlDsa65KeyPair::try_generate()` uses
//!   the Pulsar CSPRNG abstraction ([`crate::crypto::rng`]) to draw
//!   32 bytes of seed material; `try_from_seed(&seed)` takes a
//!   caller-supplied 32-byte seed for deterministic test-vector
//!   replay.
//! - **Signing** (caller holds the signing key) —
//!   `MlDsa65SigningKey::try_sign(message, context)` draws 32 bytes
//!   of signing randomness from the OS CSPRNG (per FIPS 204 § 5.4 —
//!   ML-DSA's signing operation is randomised by default for
//!   side-channel resistance, even though the algorithm has a
//!   deterministic mode); `try_sign_with_seed(message, context,
//!   &seed)` lets callers inject the randomness for testing.
//! - **Verification** (peer holds the verification key) —
//!   `MlDsa65VerificationKey::verify(message, context, &signature)`
//!   returns `Ok(())` on valid / `Err(SignatureVerifyFailed)` on
//!   invalid (no information leaked about which step of verification
//!   failed — same posture as Ed25519).
//!
//! # Sizes (FIPS 204 ML-DSA-65)
//!
//! | Element                   | Length     |
//! | ------------------------- | ---------- |
//! | Verification (public) key | 1 952 bytes|
//! | Signing (private) key     | 4 032 bytes|
//! | Signature                 | 3 309 bytes|
//! | Keypair-generation seed   | 32 bytes   |
//! | Signing randomness seed   | 32 bytes   |
//! | Context (domain-separation) max length | 255 bytes |
//!
//! All sizes are exact; the ML-DSA specification fixes them as part
//! of the parameter set.
//!
//! # Context parameter (FIPS 204 § 5.2)
//!
//! ML-DSA's `sign` / `verify` accept a context byte string of length
//! ≤ 255 used for domain separation between protocols using the same
//! signing key. Callers that don't need domain separation pass an
//! empty `&[]` context. Mismatched contexts at sign vs verify time
//! cause verification to fail. Excess-length contexts (> 255 bytes)
//! are rejected as [`Error::InputTooLong`].
//!
//! # Key-material handling
//!
//! Following the canonical pattern from Phase 1.1.B.3 / Phase 1.1.C.1
//! (ML-KEM-768):
//!
//! - **Signing keys** store the 4 032-byte private key in
//!   [`secrecy::SecretBox<[u8]>`] (zeroize-on-drop) — the Pulsar
//!   private-key convention. The wrapper materialises libcrux's
//!   `MLDSA65SigningKey` value transiently for each `try_sign*` call
//!   via a [`zeroize::Zeroizing<[u8; 4032]>`] stack buffer.
//! - **Verification keys + signatures** are unwrapped types in line
//!   with their non-secret semantics per the digital-signature threat
//!   model (verification keys + signatures are public).
//! - **Generation + signing seeds** are wrapped in [`SecretBox<[u8]>`]
//!   when caller-supplied; internal stack buffers are wrapped in
//!   [`Zeroizing`] for explicit zeroize-on-drop.

use crate::crypto::rng;
use crate::error::{Error, Result};
use libcrux_ml_dsa::ml_dsa_65 as libcrux;
use libcrux_ml_dsa::{KEY_GENERATION_RANDOMNESS_SIZE, SIGNING_RANDOMNESS_SIZE};
use secrecy::{ExposeSecret, SecretBox};
use zeroize::Zeroizing;

/// FIPS 204 ML-DSA-65 fixed sizes — re-exposed as public constants on
/// the wrapper for caller-side allocation sizing decisions.
pub mod sizes {
    /// Signing (private) key length in bytes.
    pub const SIGNING_KEY_LEN: usize = 4032;
    /// Verification (public) key length in bytes.
    pub const VERIFICATION_KEY_LEN: usize = 1952;
    /// Signature length in bytes.
    pub const SIGNATURE_LEN: usize = 3309;
    /// Keypair-generation randomness length in bytes.
    pub const KEY_GENERATION_RANDOMNESS_LEN: usize = super::KEY_GENERATION_RANDOMNESS_SIZE;
    /// Signing randomness length in bytes.
    pub const SIGNING_RANDOMNESS_LEN: usize = super::SIGNING_RANDOMNESS_SIZE;
    /// Maximum context-string length (FIPS 204 § 5.2).
    pub const MAX_CONTEXT_LEN: usize = 255;
}

/// ML-DSA-65 verification (public) key.
///
/// Wraps the libcrux `MLDSA65VerificationKey` (a 1 952-byte array).
/// Verification keys are public — they are transmitted to peers
/// alongside signed messages.
#[derive(Clone)]
pub struct MlDsa65VerificationKey {
    inner: libcrux::MLDSA65VerificationKey,
}

impl core::fmt::Debug for MlDsa65VerificationKey {
    /// Verification keys are not secret — show a short hex prefix +
    /// length for identification, mirroring [`crate::crypto::signature::Ed25519PublicKey`].
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlDsa65VerificationKey")
            .field("bytes", &super::hex_encode_short(self.inner.as_slice()))
            .finish()
    }
}

impl MlDsa65VerificationKey {
    /// Verification-key length in bytes (FIPS 204 ML-DSA-65).
    pub const LEN: usize = sizes::VERIFICATION_KEY_LEN;

    /// Construct from a 1 952-byte buffer.
    #[must_use]
    pub const fn from_bytes(bytes: [u8; Self::LEN]) -> Self {
        Self {
            inner: libcrux::MLDSA65VerificationKey::new(bytes),
        }
    }

    /// Return the verification key as a 1 952-byte slice.
    #[must_use]
    pub const fn as_bytes(&self) -> &[u8; Self::LEN] {
        self.inner.as_ref()
    }

    /// Verify `signature` over `message` with optional domain-
    /// separation `context` under this verification key. Returns
    /// `Ok(())` if the signature is valid; [`Error::SignatureVerifyFailed`]
    /// otherwise (collapses every libcrux `VerificationError` variant
    /// — malformed hint, signer-response exceeds bound, commitment-
    /// hash mismatch, context too long — into the single error
    /// variant so no information leaks about which sub-step failed,
    /// matching the [`crate::crypto::signature::Ed25519PublicKey::verify`]
    /// posture).
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `context.len() > 255` (FIPS 204 §
    ///   5.2 maximum context length).
    /// - [`Error::SignatureVerifyFailed`] — the signature is not
    ///   valid for the (verification key, message, context) triple.
    pub fn verify(
        &self,
        message: &[u8],
        context: &[u8],
        signature: &MlDsa65Signature,
    ) -> Result<()> {
        if context.len() > sizes::MAX_CONTEXT_LEN {
            return Err(Error::InputTooLong {
                actual: context.len(),
                max: sizes::MAX_CONTEXT_LEN,
            });
        }
        libcrux::verify(&self.inner, message, context, &signature.inner)
            .map_err(|_| Error::SignatureVerifyFailed)
    }
}

/// ML-DSA-65 signing (private) key.
///
/// Stores the 4 032-byte private key in [`secrecy::SecretBox<[u8]>`]
/// (zeroize-on-drop) — the canonical Pulsar private-key convention.
/// libcrux's `MLDSA65SigningKey` value is materialised transiently
/// for each `try_sign*` call; the trust window is microseconds (one
/// signing operation) and subsequent stack frames overwrite the
/// transient bytes (libcrux 0.0.6 does not implement `Zeroize`).
pub struct MlDsa65SigningKey {
    bytes: SecretBox<[u8]>,
}

impl core::fmt::Debug for MlDsa65SigningKey {
    /// Print only the type name — never the signing-key bytes.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlDsa65SigningKey").finish_non_exhaustive()
    }
}

impl MlDsa65SigningKey {
    /// Signing-key length in bytes (FIPS 204 ML-DSA-65).
    pub const LEN: usize = sizes::SIGNING_KEY_LEN;

    /// Construct from a 4 032-byte buffer wrapped in [`SecretBox<[u8]>`].
    /// Consumes the input `SecretBox` (matches the canonical
    /// consume-pattern for private-key wrappers in `crypto::mod`).
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `secret.expose_secret().len() != 4032`.
    pub fn from_bytes(secret: SecretBox<[u8]>) -> Result<Self> {
        if secret.expose_secret().len() != Self::LEN {
            return Err(Error::InvalidKeyLength {
                expected: Self::LEN,
                actual: secret.expose_secret().len(),
            });
        }
        Ok(Self { bytes: secret })
    }

    /// Materialise libcrux's `MLDSA65SigningKey` value from our
    /// `SecretBox<[u8]>` storage. Returns the libcrux instance plus
    /// a [`Zeroizing`] guard for the stack-side intermediate copy
    /// (zeroized on drop).
    fn materialize(&self) -> (libcrux::MLDSA65SigningKey, Zeroizing<[u8; Self::LEN]>) {
        let mut buf = Zeroizing::new([0_u8; Self::LEN]);
        buf.copy_from_slice(self.bytes.expose_secret());
        let inner = libcrux::MLDSA65SigningKey::new(*buf);
        (inner, buf)
    }

    /// Sign `message` with optional domain-separation `context` under
    /// this signing key, drawing 32 bytes of CSPRNG randomness for
    /// the rejection-sampling loop. Per FIPS 204 § 5.4, ML-DSA
    /// signing is randomised by default — the same `(key, message,
    /// context)` triple yields different signatures across calls.
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `context.len() > 255` (FIPS 204 §
    ///   5.2 maximum context length).
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during randomness generation.
    /// - [`Error::SigningFailed`] — libcrux's rejection-sampling
    ///   loop exceeded the maximum number of attempts (vanishingly
    ///   rare; treat as fatal-but-transient).
    pub fn try_sign(&self, message: &[u8], context: &[u8]) -> Result<MlDsa65Signature> {
        let mut seed = Zeroizing::new([0_u8; SIGNING_RANDOMNESS_SIZE]);
        rng::try_random_into(seed.as_mut_slice())?;
        self.sign_with_randomness(message, context, *seed)
    }

    /// Sign with a caller-supplied 32-byte randomness seed. Used by
    /// deterministic-test-vector replay and by hybrid-signature
    /// constructions that derive the seed from upstream entropy
    /// combinations.
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `context.len() > 255`.
    /// - [`Error::InvalidKeyLength`] — `seed.expose_secret().len() != 32`.
    /// - [`Error::SigningFailed`] — see [`try_sign`](Self::try_sign).
    pub fn try_sign_with_seed(
        &self,
        message: &[u8],
        context: &[u8],
        seed: &SecretBox<[u8]>,
    ) -> Result<MlDsa65Signature> {
        let bytes = seed.expose_secret();
        if bytes.len() != SIGNING_RANDOMNESS_SIZE {
            return Err(Error::InvalidKeyLength {
                expected: SIGNING_RANDOMNESS_SIZE,
                actual: bytes.len(),
            });
        }
        let mut seed_array = Zeroizing::new([0_u8; SIGNING_RANDOMNESS_SIZE]);
        seed_array.copy_from_slice(bytes);
        self.sign_with_randomness(message, context, *seed_array)
    }

    /// Internal signing entry point that takes the randomness as a
    /// fixed-size array. Both `try_sign*` variants funnel through
    /// here so the libcrux invocation lives in one place.
    fn sign_with_randomness(
        &self,
        message: &[u8],
        context: &[u8],
        randomness: [u8; SIGNING_RANDOMNESS_SIZE],
    ) -> Result<MlDsa65Signature> {
        if context.len() > sizes::MAX_CONTEXT_LEN {
            return Err(Error::InputTooLong {
                actual: context.len(),
                max: sizes::MAX_CONTEXT_LEN,
            });
        }
        let (inner_key, _zeroizing_guard) = self.materialize();
        let signature = libcrux::sign(&inner_key, message, context, randomness)
            .map_err(|_| Error::SigningFailed)?;
        Ok(MlDsa65Signature { inner: signature })
    }
}

/// ML-DSA-65 signature (3 309 bytes).
#[derive(Clone)]
pub struct MlDsa65Signature {
    inner: libcrux::MLDSA65Signature,
}

impl core::fmt::Debug for MlDsa65Signature {
    /// Signatures are not secret per FIPS 204 (only the signing key
    /// is). Show a short hex prefix + length for identification.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlDsa65Signature")
            .field("bytes", &super::hex_encode_short(self.inner.as_slice()))
            .finish()
    }
}

impl MlDsa65Signature {
    /// Signature length in bytes (FIPS 204 ML-DSA-65).
    pub const LEN: usize = sizes::SIGNATURE_LEN;

    /// Construct from a 3 309-byte buffer.
    #[must_use]
    pub const fn from_bytes(bytes: [u8; Self::LEN]) -> Self {
        Self {
            inner: libcrux::MLDSA65Signature::new(bytes),
        }
    }

    /// Return the signature as a 3 309-byte slice.
    #[must_use]
    pub const fn as_bytes(&self) -> &[u8; Self::LEN] {
        self.inner.as_ref()
    }
}

/// ML-DSA-65 keypair — bundles a verification (public) + signing
/// (private) key produced by a single keypair-generation run.
pub struct MlDsa65KeyPair {
    verification_key: MlDsa65VerificationKey,
    signing_key: MlDsa65SigningKey,
}

impl core::fmt::Debug for MlDsa65KeyPair {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlDsa65KeyPair")
            .field("verification_key", &self.verification_key)
            .field("signing_key", &self.signing_key)
            .finish()
    }
}

impl MlDsa65KeyPair {
    /// Generate a fresh ML-DSA-65 keypair using the OS CSPRNG.
    ///
    /// Draws 32 bytes of randomness via [`crate::crypto::rng::try_random_into`].
    /// For deterministic test-vector replay, use
    /// [`try_from_seed`](Self::try_from_seed).
    ///
    /// # Errors
    ///
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during seed generation.
    pub fn try_generate() -> Result<Self> {
        let mut seed = Zeroizing::new([0_u8; KEY_GENERATION_RANDOMNESS_SIZE]);
        rng::try_random_into(seed.as_mut_slice())?;
        Ok(Self::from_seed_bytes(*seed))
    }

    /// Generate a keypair from a caller-supplied 32-byte seed wrapped
    /// in [`SecretBox<[u8]>`]. Used for deterministic test-vector
    /// replay (FIPS 204 ACVP reference vectors) and for hybrid-
    /// signature constructions.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `seed.expose_secret().len() != 32`.
    pub fn try_from_seed(seed: &SecretBox<[u8]>) -> Result<Self> {
        let bytes = seed.expose_secret();
        if bytes.len() != KEY_GENERATION_RANDOMNESS_SIZE {
            return Err(Error::InvalidKeyLength {
                expected: KEY_GENERATION_RANDOMNESS_SIZE,
                actual: bytes.len(),
            });
        }
        let mut seed_array = Zeroizing::new([0_u8; KEY_GENERATION_RANDOMNESS_SIZE]);
        seed_array.copy_from_slice(bytes);
        Ok(Self::from_seed_bytes(*seed_array))
    }

    /// Internal seed-driven constructor. Persistent signing-key
    /// storage is `SecretBox<[u8]>` (zeroize-on-drop); the libcrux
    /// transient lives only for the duration of this function.
    fn from_seed_bytes(seed: [u8; KEY_GENERATION_RANDOMNESS_SIZE]) -> Self {
        let kp = libcrux::generate_key_pair(seed);
        let sk_libcrux = kp.signing_key;
        let vk_libcrux = kp.verification_key;

        // Copy libcrux's signing-key bytes into a Zeroizing stack
        // buffer, then into the persistent SecretBox<[u8]>. The
        // libcrux value's internal 4 032 bytes are not zeroized but
        // live only for the remainder of this function.
        let mut sk_buf = Zeroizing::new([0_u8; sizes::SIGNING_KEY_LEN]);
        sk_buf.copy_from_slice(sk_libcrux.as_slice());
        let sk_secret = SecretBox::new(sk_buf.to_vec().into_boxed_slice());

        Self {
            verification_key: MlDsa65VerificationKey { inner: vk_libcrux },
            signing_key: MlDsa65SigningKey { bytes: sk_secret },
        }
    }

    /// Return a reference to the verification (public) key.
    #[must_use]
    pub const fn verification_key(&self) -> &MlDsa65VerificationKey {
        &self.verification_key
    }

    /// Return a reference to the signing (private) key.
    #[must_use]
    pub const fn signing_key(&self) -> &MlDsa65SigningKey {
        &self.signing_key
    }

    /// Decompose the keypair into its constituent verification +
    /// signing keys, transferring ownership.
    #[must_use]
    pub fn into_parts(self) -> (MlDsa65VerificationKey, MlDsa65SigningKey) {
        (self.verification_key, self.signing_key)
    }
}
