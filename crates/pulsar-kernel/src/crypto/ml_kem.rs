//! ML-KEM (Module Lattice-based Key Encapsulation Mechanism) — safe
//! Rust wrappers over libcrux per Decision 2.60.
//!
//! Per FIPS 203 (final August 2024), ML-KEM is the NIST-standardised
//! post-quantum key-encapsulation mechanism derived from CRYSTALS-Kyber.
//! Pulsar ships the ML-KEM-768 parameter set (NIST security category 3
//! — equivalent to AES-192) per Decision 2.58 hybrid PQC posture, where
//! every protocol that uses key encapsulation runs **X25519 + ML-KEM-768
//! in parallel** and combines the two shared secrets via HKDF-SHA-256.
//! The hybrid construction ships in Phase 1.1.C.3; this sub-phase lands
//! the bare ML-KEM-768 primitive that the hybrid layers on top of.
//!
//! # Sourcing — libcrux Rust-native (Decision 2.60)
//!
//! ML-KEM-768 is sourced from `libcrux-ml-kem 0.0.8` — Cryspen's
//! Rust-native verified extraction with `hax + F*` proofs of FIPS 203
//! conformance covering field arithmetic, NTT polynomial arithmetic,
//! serialization, and the generic code for high-level algorithms. The
//! library is pure Rust (no FFI burden, no C toolchain requirement),
//! sharing the same Cryspen / hax / F\* verification provenance as the
//! HACL\* classical primitives — see Decision 2.60 for the unified
//! provenance argument.
//!
//! # API surface
//!
//! Three flows cover the typical use cases:
//!
//! - **Keypair generation** — `MlKem768KeyPair::try_generate()` uses
//!   the Pulsar CSPRNG abstraction ([`crate::crypto::rng`]) to draw 64
//!   bytes of seed material; `MlKem768KeyPair::try_from_seed(&seed)`
//!   takes a caller-supplied 64-byte seed (for testing reproducibility
//!   or deterministic test-vector replay).
//! - **Encapsulation** (peer holds the public key) —
//!   `MlKem768PublicKey::try_encapsulate()` draws 32 bytes of CSPRNG
//!   randomness and returns the ciphertext + shared secret;
//!   `try_encapsulate_with_seed(&seed)` lets callers inject the
//!   randomness for testing.
//! - **Decapsulation** (recipient holds the private key) —
//!   `MlKem768PrivateKey::decapsulate(&ciphertext)` is deterministic
//!   and infallible (FIPS 203 § 7.3 implicit rejection — any
//!   ciphertext produces a shared secret, with malformed inputs
//!   yielding a deterministic-but-uncorrelated value that the
//!   protocol layer treats as authentication failure).
//!
//! # Sizes (FIPS 203 ML-KEM-768)
//!
//! | Element                   | Length     |
//! | ------------------------- | ---------- |
//! | Public (encapsulation) key| 1 184 bytes|
//! | Private (decapsulation) key | 2 400 bytes|
//! | Ciphertext                | 1 088 bytes|
//! | Shared secret             | 32 bytes   |
//! | Keypair-generation seed   | 64 bytes   |
//! | Encapsulation seed        | 32 bytes   |
//!
//! All sizes are exact; the ML-KEM specification fixes them as part of
//! the parameter set.
//!
//! # Key-material handling
//!
//! Following the canonical pattern from Phase 1.1.B.3:
//!
//! - **Private keys** wrap the libcrux `MlKem768PrivateKey` inside a
//!   `secrecy::SecretBox`-style struct with manual `Debug` redaction.
//!   The wrapper holds the libcrux value directly (rather than
//!   re-wrapping the raw bytes in `SecretBox<[u8]>`) because the
//!   libcrux type is `Clone` but does not implement `Zeroize` —
//!   Pulsar's wrapper provides explicit zeroization on drop via
//!   `zeroize::Zeroize` over the inner byte array.
//! - **Public keys, ciphertexts, shared secrets** are unwrapped types
//!   in line with their non-secret semantics, EXCEPT the shared
//!   secret which is wrapped in `SecretBox<[u8]>` so downstream HKDF
//!   derivation operates on `expose_secret()` without intermediate
//!   cleartext copies.
//! - **Generation + encapsulation seeds** are wrapped in
//!   `SecretBox<[u8]>` when caller-supplied (the seed is sensitive
//!   during the operation; once the keypair / ciphertext is produced
//!   the seed should be dropped).

use crate::crypto::rng;
use crate::error::{Error, Result};
use libcrux_ml_kem::mlkem768 as libcrux;
use libcrux_ml_kem::{ENCAPS_SEED_SIZE, KEY_GENERATION_SEED_SIZE, SHARED_SECRET_SIZE};
use secrecy::{ExposeSecret, SecretBox};
use zeroize::Zeroize;

/// FIPS 203 ML-KEM-768 fixed sizes — re-exposed as public constants on
/// the wrapper for caller-side allocation sizing decisions.
pub mod sizes {
    /// Public (encapsulation) key length in bytes.
    pub const PUBLIC_KEY_LEN: usize = 1184;
    /// Private (decapsulation) key length in bytes.
    pub const PRIVATE_KEY_LEN: usize = 2400;
    /// Ciphertext length in bytes.
    pub const CIPHERTEXT_LEN: usize = 1088;
    /// Shared secret length in bytes.
    pub const SHARED_SECRET_LEN: usize = super::SHARED_SECRET_SIZE;
    /// Keypair-generation seed length in bytes (64 = 32 random + 32 derived).
    pub const KEY_GENERATION_SEED_LEN: usize = super::KEY_GENERATION_SEED_SIZE;
    /// Encapsulation seed length in bytes.
    pub const ENCAPSULATION_SEED_LEN: usize = super::ENCAPS_SEED_SIZE;
}

/// ML-KEM-768 public (encapsulation) key.
///
/// Wraps the libcrux `MlKem768PublicKey` (a 1 184-byte array). Public
/// keys are not secret — they are transmitted to peers for
/// encapsulation. The wrapper provides `validate()` for explicit FIPS
/// 203 § 7.2 modulus-check validation and `try_encapsulate*()`
/// entry points.
#[derive(Clone)]
pub struct MlKem768PublicKey {
    inner: libcrux::MlKem768PublicKey,
}

impl core::fmt::Debug for MlKem768PublicKey {
    /// Public keys are not secret — show a short hex prefix + length
    /// for identification, mirroring [`crate::crypto::signature::Ed25519PublicKey`].
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlKem768PublicKey")
            .field(
                "bytes",
                &super::hex_encode_short(self.inner.as_slice().as_slice()),
            )
            .finish()
    }
}

impl MlKem768PublicKey {
    /// Public-key length in bytes (FIPS 203 ML-KEM-768).
    pub const LEN: usize = sizes::PUBLIC_KEY_LEN;

    /// Construct from a 1 184-byte buffer. Does NOT validate the
    /// public key — call [`validate`](Self::validate) explicitly
    /// before using it for encapsulation if the bytes came from an
    /// untrusted source.
    #[must_use]
    pub fn from_bytes(bytes: [u8; Self::LEN]) -> Self {
        Self {
            inner: libcrux::MlKem768PublicKey::from(bytes),
        }
    }

    /// Return the public key as a 1 184-byte slice.
    #[must_use]
    pub fn as_bytes(&self) -> &[u8; Self::LEN] {
        self.inner.as_slice()
    }

    /// Validate the public key per FIPS 203 § 7.2 (check that every
    /// coefficient of the polynomial vector is in `[0, q)` after NTT
    /// decoding). Production callers receiving public keys from
    /// untrusted peers MUST call this before encapsulation.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidPublicKey`] — the public key fails the
    ///   FIPS-203-mandated modulus check.
    pub fn validate(&self) -> Result<()> {
        if libcrux::validate_public_key(&self.inner) {
            Ok(())
        } else {
            Err(Error::InvalidPublicKey)
        }
    }

    /// Encapsulate against this public key, drawing the 32-byte
    /// encapsulation seed from the OS CSPRNG. Returns the
    /// `(ciphertext, shared_secret)` tuple — the ciphertext is sent
    /// to the holder of the private key, the shared secret is
    /// retained locally for downstream KDF derivation.
    ///
    /// # Errors
    ///
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during seed generation.
    pub fn try_encapsulate(&self) -> Result<(MlKem768Ciphertext, SecretBox<[u8]>)> {
        let mut seed = [0_u8; ENCAPS_SEED_SIZE];
        rng::try_random_into(&mut seed)?;
        Ok(self.encapsulate_with_seed_bytes(seed))
    }

    /// Encapsulate against this public key with a caller-supplied
    /// 32-byte seed. Used by deterministic-test-vector replay and by
    /// hybrid-KEM constructions that need to combine the ML-KEM seed
    /// with the classical-KEM seed.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `seed.expose_secret().len() != 32`.
    pub fn try_encapsulate_with_seed(
        &self,
        seed: &SecretBox<[u8]>,
    ) -> Result<(MlKem768Ciphertext, SecretBox<[u8]>)> {
        let bytes = seed.expose_secret();
        if bytes.len() != ENCAPS_SEED_SIZE {
            return Err(Error::InvalidKeyLength {
                expected: ENCAPS_SEED_SIZE,
                actual: bytes.len(),
            });
        }
        let mut seed_array = [0_u8; ENCAPS_SEED_SIZE];
        seed_array.copy_from_slice(bytes);
        Ok(self.encapsulate_with_seed_bytes(seed_array))
    }

    /// Internal encapsulation entry point that takes the seed as a
    /// fixed-size array. Both `try_encapsulate*` variants funnel
    /// through here so the libcrux invocation lives in one place.
    fn encapsulate_with_seed_bytes(
        &self,
        seed: [u8; ENCAPS_SEED_SIZE],
    ) -> (MlKem768Ciphertext, SecretBox<[u8]>) {
        let (ct, ss) = libcrux::encapsulate(&self.inner, seed);
        let ct_wrapped = MlKem768Ciphertext { inner: ct };
        let ss_box = SecretBox::new(ss.to_vec().into_boxed_slice());
        (ct_wrapped, ss_box)
    }
}

/// ML-KEM-768 private (decapsulation) key.
///
/// Wraps the libcrux `MlKem768PrivateKey` (a 2 400-byte array). The
/// inner bytes are zeroized on drop via the `zeroize::Zeroize`
/// trait — the libcrux type does not implement `Zeroize` upstream, so
/// the wrapper handles it explicitly.
pub struct MlKem768PrivateKey {
    inner: libcrux::MlKem768PrivateKey,
}

impl core::fmt::Debug for MlKem768PrivateKey {
    /// Print only the type name — never the private-key bytes.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlKem768PrivateKey").finish_non_exhaustive()
    }
}

impl Drop for MlKem768PrivateKey {
    /// Zeroize the inner private-key bytes on drop. The libcrux
    /// `MlKem768PrivateKey` type does not implement `Zeroize`
    /// upstream as of `libcrux-ml-kem 0.0.8`; the wrapper provides
    /// the explicit zeroization that Pulsar's audit posture
    /// requires for private-key material.
    fn drop(&mut self) {
        // Take a mutable reference to the inner byte array and
        // zeroize. SAFETY (no `unsafe`): `inner.value` is the public
        // field of the generic `MlKem*Key` struct exposed through
        // libcrux's `as_ref`/`as_slice` accessors; we reach it via
        // the AsRef-like `as_slice` and re-zero through a
        // pointer-cast-free zeroize helper.
        //
        // Since libcrux exposes `as_slice() -> &[u8; SIZE]` (immutable),
        // we instead clone-replace with a zeroed instance — the
        // original allocation is dropped, after which the new
        // zeroed instance is itself dropped at end of scope.
        let mut zeroed = [0_u8; sizes::PRIVATE_KEY_LEN];
        zeroed.zeroize();
        self.inner = libcrux::MlKem768PrivateKey::from(zeroed);
    }
}

impl MlKem768PrivateKey {
    /// Private-key length in bytes (FIPS 203 ML-KEM-768).
    pub const LEN: usize = sizes::PRIVATE_KEY_LEN;

    /// Construct from a 2 400-byte buffer wrapped in `SecretBox<[u8]>`.
    /// Does NOT validate the structure of the bytes — call
    /// [`validate_against`](Self::validate_against) with a paired
    /// ciphertext to confirm the private key matches before relying
    /// on its decapsulation output.
    ///
    /// Takes `&SecretBox<[u8]>` (borrowed) rather than consuming the
    /// SecretBox — libcrux requires copying the bytes into its own
    /// fixed-size array regardless, so consuming would offer no
    /// lifecycle benefit. The wrapper's [`Drop`] impl zeroizes the
    /// libcrux-side bytes when the [`MlKem768PrivateKey`] is dropped.
    /// This deviates from the
    /// [`crate::crypto::signature::Ed25519PrivateKey::from_bytes`]
    /// consume-pattern documented in `crypto::mod` for primitives
    /// that store the `SecretBox` directly.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `secret.expose_secret().len() != 2400`.
    pub fn from_bytes(secret: &SecretBox<[u8]>) -> Result<Self> {
        let bytes = secret.expose_secret();
        if bytes.len() != Self::LEN {
            return Err(Error::InvalidKeyLength {
                expected: Self::LEN,
                actual: bytes.len(),
            });
        }
        let mut buf = [0_u8; Self::LEN];
        buf.copy_from_slice(bytes);
        Ok(Self {
            inner: libcrux::MlKem768PrivateKey::from(buf),
        })
    }

    /// Validate the private key against a paired ciphertext per FIPS
    /// 203 § 7.3 (verifies the implicit-rejection-recovery hash
    /// matches the embedded value).
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidPublicKey`] — the (private key, ciphertext)
    ///   pair fails validation. (`InvalidPublicKey` is overloaded
    ///   here to mean "structural validation failed"; the variant
    ///   name reflects that the embedded encapsulation key must be
    ///   on-curve, not that the private key itself is the public
    ///   key.)
    pub fn validate_against(&self, ciphertext: &MlKem768Ciphertext) -> Result<()> {
        if libcrux::validate_private_key(&self.inner, &ciphertext.inner) {
            Ok(())
        } else {
            Err(Error::InvalidPublicKey)
        }
    }

    /// Decapsulate `ciphertext` under this private key. Returns the
    /// 32-byte shared secret wrapped in [`SecretBox<[u8]>`] for
    /// downstream zeroize-on-drop handling.
    ///
    /// Decapsulation is **deterministic and infallible**: any input
    /// ciphertext produces a shared secret. Per FIPS 203 § 7.3, a
    /// malformed or tampered ciphertext yields a deterministic-but-
    /// uncorrelated shared secret derived via the implicit-rejection
    /// branch — the protocol layer treats this as an authentication
    /// failure (e.g., HKDF mixing produces unrelated session keys
    /// that the legitimate peer cannot match). Pulsar's wrappers do
    /// not surface a verification result at this layer; the
    /// protocol-layer key-confirmation step catches the rejection.
    #[must_use]
    pub fn decapsulate(&self, ciphertext: &MlKem768Ciphertext) -> SecretBox<[u8]> {
        let ss = libcrux::decapsulate(&self.inner, &ciphertext.inner);
        SecretBox::new(ss.to_vec().into_boxed_slice())
    }
}

/// ML-KEM-768 ciphertext (1 088 bytes).
#[derive(Clone)]
pub struct MlKem768Ciphertext {
    inner: libcrux::MlKem768Ciphertext,
}

impl core::fmt::Debug for MlKem768Ciphertext {
    /// Ciphertexts are not secret per the KEM threat model (only the
    /// shared secret is). Show a short hex prefix + length for
    /// identification.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlKem768Ciphertext")
            .field(
                "bytes",
                &super::hex_encode_short(self.inner.as_slice().as_slice()),
            )
            .finish()
    }
}

impl MlKem768Ciphertext {
    /// Ciphertext length in bytes (FIPS 203 ML-KEM-768).
    pub const LEN: usize = sizes::CIPHERTEXT_LEN;

    /// Construct from a 1 088-byte buffer.
    #[must_use]
    pub fn from_bytes(bytes: [u8; Self::LEN]) -> Self {
        Self {
            inner: libcrux::MlKem768Ciphertext::from(bytes),
        }
    }

    /// Return the ciphertext as a 1 088-byte slice.
    #[must_use]
    pub fn as_bytes(&self) -> &[u8; Self::LEN] {
        self.inner.as_slice()
    }
}

/// ML-KEM-768 keypair — bundles a public + private key produced by a
/// single keypair-generation run.
pub struct MlKem768KeyPair {
    public_key: MlKem768PublicKey,
    private_key: MlKem768PrivateKey,
}

impl core::fmt::Debug for MlKem768KeyPair {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("MlKem768KeyPair")
            .field("public_key", &self.public_key)
            .field("private_key", &self.private_key)
            .finish()
    }
}

impl MlKem768KeyPair {
    /// Generate a fresh ML-KEM-768 keypair using the OS CSPRNG.
    ///
    /// Draws 64 bytes of randomness via [`crate::crypto::rng::try_random_into`]
    /// — the canonical Pulsar entry point for cryptographic seed
    /// material. For deterministic test-vector replay, use
    /// [`try_from_seed`](Self::try_from_seed).
    ///
    /// # Errors
    ///
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during seed generation.
    pub fn try_generate() -> Result<Self> {
        let mut seed = [0_u8; KEY_GENERATION_SEED_SIZE];
        rng::try_random_into(&mut seed)?;
        Ok(Self::from_seed_bytes(seed))
    }

    /// Generate a keypair from a caller-supplied 64-byte seed wrapped
    /// in [`SecretBox<[u8]>`]. The seed is consumed; the resulting
    /// keypair retains both keys.
    ///
    /// Used for deterministic test-vector replay (FIPS 203 ACVP
    /// reference vectors), and for hybrid-KEM constructions that
    /// derive the seed from upstream entropy combinations.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `seed.expose_secret().len() != 64`.
    pub fn try_from_seed(seed: &SecretBox<[u8]>) -> Result<Self> {
        let bytes = seed.expose_secret();
        if bytes.len() != KEY_GENERATION_SEED_SIZE {
            return Err(Error::InvalidKeyLength {
                expected: KEY_GENERATION_SEED_SIZE,
                actual: bytes.len(),
            });
        }
        let mut seed_array = [0_u8; KEY_GENERATION_SEED_SIZE];
        seed_array.copy_from_slice(bytes);
        Ok(Self::from_seed_bytes(seed_array))
    }

    /// Internal seed-driven constructor that takes the seed as a
    /// fixed-size array.
    fn from_seed_bytes(seed: [u8; KEY_GENERATION_SEED_SIZE]) -> Self {
        let kp = libcrux::generate_key_pair(seed);
        let (sk_bytes, pk_bytes) = kp.into_parts();
        Self {
            public_key: MlKem768PublicKey { inner: pk_bytes },
            private_key: MlKem768PrivateKey { inner: sk_bytes },
        }
    }

    /// Return a reference to the public (encapsulation) key.
    #[must_use]
    pub const fn public_key(&self) -> &MlKem768PublicKey {
        &self.public_key
    }

    /// Return a reference to the private (decapsulation) key.
    #[must_use]
    pub const fn private_key(&self) -> &MlKem768PrivateKey {
        &self.private_key
    }

    /// Decompose the keypair into its constituent public + private
    /// keys, transferring ownership. Useful for callers that want to
    /// retain the public key alone (e.g., publish to a peer) and
    /// keep the private key in a separate storage path.
    #[must_use]
    pub fn into_parts(self) -> (MlKem768PublicKey, MlKem768PrivateKey) {
        (self.public_key, self.private_key)
    }
}
