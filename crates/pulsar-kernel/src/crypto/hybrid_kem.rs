//! Hybrid post-quantum + classical key-encapsulation mechanism.
//!
//! Per Decision 2.58 hybrid PQC posture from Sprint 1.1, every protocol
//! that uses key encapsulation runs **X25519 + ML-KEM-768 in parallel**
//! and combines the resulting shared secrets via HKDF-SHA-256. This
//! sub-phase ships the safe Rust wrapper that orchestrates the two
//! constituent primitives (security-reviewed in Phase 1.1.B.3 for
//! X25519 and Phase 1.1.C.1 for ML-KEM-768).
//!
//! # Why hybrid KEM
//!
//! - **Quantum resistance** — ML-KEM-768 is FIPS 203's NIST-PQC KEM.
//!   A future quantum adversary cannot break the ML-KEM-768 share.
//! - **Classical fallback** — X25519 is RFC 7748's elliptic-curve
//!   Diffie-Hellman with two decades of cryptanalytic scrutiny. If a
//!   structural attack is discovered against ML-KEM-768 (the lattice-
//!   assumption analogue of "P = NP for SVP"), the classical share
//!   still provides 128-bit-equivalent security.
//! - **Defence in depth via independent primitives** — the two
//!   schemes share neither hardness assumption nor implementation
//!   surface. A break against one does not propagate to the other.
//!
//! Reference deployments: Cloudflare PQ-Hybrid (Cloudflare TLS), Google
//! Chrome PQ-TLS, Apple iMessage PQ3, IETF
//! draft-ietf-tls-hybrid-design.
//!
//! # Combiner
//!
//! Per Decision 2.58, the shared secrets are combined via HKDF-SHA-256:
//!
//! ```text
//! IKM = ss_x25519 || ss_mlkem            // 32 + 32 = 64 bytes
//! salt = "pulsar-hybrid-kem-x25519-mlkem768-v1"
//! info = ""
//! shared_secret = HKDF-SHA-256(salt, IKM, info, 32)
//! ```
//!
//! The salt label provides domain separation against any other Pulsar
//! HKDF use of the same IKM. The combiner is structurally an HKDF-Extract
//! followed by HKDF-Expand to 32 bytes (matching the [`crate::crypto::hkdf::hkdf`]
//! convenience helper).
//!
//! # Wire format
//!
//! - **Public key** = `pk_x25519 || pk_mlkem`              (32  + 1 184 = 1 216 bytes)
//! - **Private key** = stored separately as `(X25519PrivateKey, MlKem768PrivateKey)`
//!   — the wire format for serialization is `sk_x25519 || sk_mlkem` (32 + 2 400 = 2 432 bytes)
//! - **Ciphertext** = `eph_pk_x25519 || ct_mlkem`          (32  + 1 088 = 1 120 bytes)
//!
//! These layouts match the IETF X25519MLKEM768 codepoint convention
//! (X25519 share first, ML-KEM-768 share second).
//!
//! # Encapsulation flow
//!
//! 1. Encapsulator generates an **ephemeral X25519 keypair** (`eph_sk`, `eph_pk`).
//! 2. Encapsulator computes `ss_x25519 = eph_sk.dh(peer_pk_x25519)`.
//! 3. Encapsulator runs `ML-KEM-768::encapsulate(peer_pk_mlkem) -> (ct_mlkem, ss_mlkem)`.
//! 4. Encapsulator combines `(ss_x25519, ss_mlkem)` via HKDF.
//! 5. Encapsulator transmits `eph_pk || ct_mlkem` to the peer.
//!
//! Decapsulation is symmetric: extract `eph_pk_x25519` and `ct_mlkem`
//! from the ciphertext, do `ss_x25519 = my_sk_x25519.dh(eph_pk_x25519)`,
//! `ss_mlkem = my_sk_mlkem.decapsulate(ct_mlkem)`, combine via HKDF.

use crate::crypto::hkdf;
use crate::crypto::hmac::HmacAlgorithm;
use crate::crypto::kem::{X25519PrivateKey, X25519PublicKey};
use crate::crypto::ml_kem::{
    MlKem768Ciphertext, MlKem768KeyPair, MlKem768PrivateKey, MlKem768PublicKey,
};
use crate::error::{Error, Result};
use secrecy::{ExposeSecret, SecretBox};

/// HKDF salt used as the hybrid-KEM domain-separation label. Versioned
/// so a future combiner change can revise the salt without colliding
/// with stored material derived from the v1 combiner.
const HYBRID_SALT: &[u8] = b"pulsar-hybrid-kem-x25519-mlkem768-v1";

/// Hybrid X25519 + ML-KEM-768 fixed sizes — re-exposed as public
/// constants for caller-side allocation sizing.
pub mod sizes {
    /// Hybrid public-key length in bytes: `32 + 1184 = 1216`.
    pub const PUBLIC_KEY_LEN: usize = 32 + 1184;
    /// Hybrid private-key wire-format length in bytes: `32 + 2400 = 2432`.
    pub const PRIVATE_KEY_LEN: usize = 32 + 2400;
    /// Hybrid ciphertext length in bytes: `32 + 1088 = 1120`.
    pub const CIPHERTEXT_LEN: usize = 32 + 1088;
    /// Hybrid shared-secret length in bytes (HKDF-Expand output).
    pub const SHARED_SECRET_LEN: usize = 32;
    /// X25519 share offset within the ciphertext (`[0..32]`).
    pub const X25519_OFFSET: usize = 0;
    /// X25519 share length within the ciphertext / public key.
    pub const X25519_LEN: usize = 32;
    /// ML-KEM-768 share offset within the public key (`[32..1216]`).
    pub const MLKEM_PUBLIC_KEY_OFFSET: usize = 32;
    /// ML-KEM-768 share offset within the ciphertext (`[32..1120]`).
    pub const MLKEM_CIPHERTEXT_OFFSET: usize = 32;
    /// ML-KEM-768 ciphertext length within the hybrid ciphertext.
    pub const MLKEM_CIPHERTEXT_LEN: usize = 1088;
    /// ML-KEM-768 public key length within the hybrid public key.
    pub const MLKEM_PUBLIC_KEY_LEN: usize = 1184;
}

/// Hybrid (classical + post-quantum) KEM public key.
///
/// Holds an [`X25519PublicKey`] alongside an [`MlKem768PublicKey`].
/// Encapsulation runs both KEMs in parallel and combines the
/// resulting shared secrets per Decision 2.58.
#[derive(Clone)]
pub struct HybridKemPublicKey {
    classical: X25519PublicKey,
    pqc: MlKem768PublicKey,
}

impl core::fmt::Debug for HybridKemPublicKey {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridKemPublicKey")
            .field("classical", &self.classical)
            .field("pqc", &self.pqc)
            .finish()
    }
}

impl HybridKemPublicKey {
    /// Hybrid public-key length in bytes (`32 + 1184 = 1216`).
    pub const LEN: usize = sizes::PUBLIC_KEY_LEN;

    /// Construct from a 1 216-byte buffer in IETF X25519MLKEM768 wire
    /// format: `pk_x25519 || pk_mlkem`. Does NOT validate either
    /// share — call [`validate`](Self::validate) before encapsulating
    /// against keys received from untrusted peers.
    #[must_use]
    pub fn from_bytes(bytes: &[u8; Self::LEN]) -> Self {
        let mut x25519_bytes = [0_u8; 32];
        x25519_bytes.copy_from_slice(&bytes[0..32]);
        let classical = X25519PublicKey::from_bytes(x25519_bytes);

        let mut mlkem_bytes = [0_u8; sizes::MLKEM_PUBLIC_KEY_LEN];
        mlkem_bytes.copy_from_slice(&bytes[32..]);
        let pqc = MlKem768PublicKey::from_bytes(mlkem_bytes);

        Self { classical, pqc }
    }

    /// Construct from individual X25519 + ML-KEM-768 public keys.
    /// Used when the caller already holds the constituent public keys
    /// in their typed forms (e.g., from a structured key-exchange
    /// payload).
    #[must_use]
    pub const fn from_parts(classical: X25519PublicKey, pqc: MlKem768PublicKey) -> Self {
        Self { classical, pqc }
    }

    /// Return the X25519 share.
    #[must_use]
    pub const fn classical(&self) -> &X25519PublicKey {
        &self.classical
    }

    /// Return the ML-KEM-768 share.
    #[must_use]
    pub const fn pqc(&self) -> &MlKem768PublicKey {
        &self.pqc
    }

    /// Serialize the hybrid public key to the IETF X25519MLKEM768 wire
    /// format: `pk_x25519 || pk_mlkem`.
    #[must_use]
    pub fn to_bytes(&self) -> [u8; Self::LEN] {
        let mut out = [0_u8; Self::LEN];
        out[0..32].copy_from_slice(self.classical.as_bytes());
        out[32..].copy_from_slice(self.pqc.as_bytes());
        out
    }

    /// Validate the hybrid public key.
    ///
    /// **Scope**: validates ONLY the ML-KEM-768 share per FIPS 203 §
    /// 7.2 (modulus check). The X25519 share has no spec-mandated
    /// pre-DH validation step — RFC 7748 specifies small-order
    /// rejection at Diffie-Hellman time, which HACL\* enforces inside
    /// [`X25519PrivateKey::diffie_hellman`] via its F\* postcondition
    /// (returning [`Error::InvalidPublicKey`] from
    /// [`try_encapsulate`](Self::try_encapsulate) when the X25519
    /// share is small-order). Callers that need eager X25519
    /// validation must probe via a dummy DH against a fresh
    /// ephemeral key — Pulsar's wrapper deliberately defers this to
    /// keep `validate()` free of latency-introducing dummy operations.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidPublicKey`] — the ML-KEM share fails its
    ///   FIPS-203 § 7.2 modulus check.
    pub fn validate(&self) -> Result<()> {
        self.pqc.validate()
    }

    /// Encapsulate against this hybrid public key.
    ///
    /// 1. Generate an ephemeral X25519 keypair.
    /// 2. Compute `ss_x25519 = eph_sk.dh(self.classical)`.
    /// 3. Run `ML-KEM-768::encapsulate(self.pqc) -> (ct_mlkem, ss_mlkem)`.
    /// 4. Combine `(ss_x25519, ss_mlkem)` via HKDF-SHA-256.
    ///
    /// Returns the hybrid ciphertext (`eph_pk_x25519 || ct_mlkem`)
    /// and the 32-byte combined shared secret.
    ///
    /// # Errors
    ///
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during EITHER the ephemeral X25519 keypair generation (32
    ///   bytes via [`X25519PrivateKey::try_generate`]) OR the ML-KEM
    ///   encapsulation seed generation (32 bytes via
    ///   [`MlKem768PublicKey::try_encapsulate`]). Both call sites
    ///   route through [`crate::crypto::rng::try_random_into`].
    /// - [`Error::InvalidPublicKey`] — the stored X25519 public key
    ///   share is a small-order point that the ephemeral DH cannot
    ///   produce a useful shared secret against (HACL\*'s F\*
    ///   postcondition rejects small-order peers).
    /// - [`Error::InvalidKeyLength`] — defensive check inside the
    ///   HKDF combiner; not reachable on the happy path because the
    ///   constituent shared secrets are spec-fixed at 32 bytes.
    pub fn try_encapsulate(&self) -> Result<(HybridKemCiphertext, SecretBox<[u8]>)> {
        // 1. Ephemeral X25519 keypair.
        let ephemeral_secret = X25519PrivateKey::try_generate()?;
        let ephemeral_public = ephemeral_secret.public_key();
        let ss_x25519 = ephemeral_secret.diffie_hellman(&self.classical)?;

        // 2. ML-KEM-768 encapsulation.
        let (ct_mlkem, ss_mlkem) = self.pqc.try_encapsulate()?;

        // 3. Combine via HKDF-SHA-256. IKM = ss_x25519 || ss_mlkem.
        let combined_ikm = combine_ikm(ss_x25519.expose_secret(), ss_mlkem.expose_secret())?;
        let shared_secret = hkdf::hkdf(
            HmacAlgorithm::Sha256,
            HYBRID_SALT,
            &combined_ikm,
            &[],
            sizes::SHARED_SECRET_LEN,
        )?;

        Ok((
            HybridKemCiphertext {
                classical: ephemeral_public,
                pqc: ct_mlkem,
            },
            shared_secret,
        ))
    }
}

/// Hybrid (classical + post-quantum) KEM private key.
///
/// Holds an [`X25519PrivateKey`] alongside an [`MlKem768PrivateKey`].
/// Decapsulation runs both KEMs in parallel and combines the
/// resulting shared secrets per Decision 2.58.
pub struct HybridKemPrivateKey {
    classical: X25519PrivateKey,
    pqc: MlKem768PrivateKey,
}

impl core::fmt::Debug for HybridKemPrivateKey {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridKemPrivateKey")
            .field("classical", &self.classical)
            .field("pqc", &self.pqc)
            .finish_non_exhaustive()
    }
}

impl HybridKemPrivateKey {
    /// Construct from individual X25519 + ML-KEM-768 private keys.
    #[must_use]
    pub const fn from_parts(classical: X25519PrivateKey, pqc: MlKem768PrivateKey) -> Self {
        Self { classical, pqc }
    }

    /// Return the X25519 share.
    #[must_use]
    pub const fn classical(&self) -> &X25519PrivateKey {
        &self.classical
    }

    /// Return the ML-KEM-768 share.
    #[must_use]
    pub const fn pqc(&self) -> &MlKem768PrivateKey {
        &self.pqc
    }

    /// Decapsulate `ciphertext` under this hybrid private key. Returns
    /// the 32-byte combined shared secret.
    ///
    /// 1. Compute `ss_x25519 = self.classical.dh(ciphertext.classical)`.
    /// 2. Run `ML-KEM-768::decapsulate(ciphertext.pqc) -> ss_mlkem`.
    /// 3. Combine `(ss_x25519, ss_mlkem)` via HKDF-SHA-256.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidPublicKey`] — the X25519 ephemeral share in
    ///   `ciphertext` is a small-order point (HACL\*'s F\*
    ///   postcondition rejects this).
    /// - [`Error::InvalidKeyLength`] — defensive check inside the
    ///   HKDF combiner; not reachable on the happy path because the
    ///   constituent shared secrets are spec-fixed at 32 bytes.
    pub fn try_decapsulate(&self, ciphertext: &HybridKemCiphertext) -> Result<SecretBox<[u8]>> {
        // 1. X25519 DH against the ciphertext's ephemeral share.
        let ss_x25519 = self.classical.diffie_hellman(&ciphertext.classical)?;

        // 2. ML-KEM-768 decapsulation. Note: ML-KEM decap is infallible
        // per FIPS 203 § 7.3 implicit rejection — a tampered ML-KEM
        // ciphertext yields a deterministic-but-uncorrelated ss_mlkem,
        // which the HKDF combiner mixes into a useless shared secret
        // (the legitimate peer cannot match). The protocol layer
        // catches this via key confirmation.
        let ss_mlkem = self.pqc.decapsulate(&ciphertext.pqc);

        // 3. Combine via HKDF-SHA-256.
        let combined_ikm = combine_ikm(ss_x25519.expose_secret(), ss_mlkem.expose_secret())?;
        hkdf::hkdf(
            HmacAlgorithm::Sha256,
            HYBRID_SALT,
            &combined_ikm,
            &[],
            sizes::SHARED_SECRET_LEN,
        )
    }
}

/// Hybrid KEM ciphertext (`eph_pk_x25519 || ct_mlkem`, 1 120 bytes).
#[derive(Clone)]
pub struct HybridKemCiphertext {
    classical: X25519PublicKey,
    pqc: MlKem768Ciphertext,
}

impl core::fmt::Debug for HybridKemCiphertext {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridKemCiphertext")
            .field("classical", &self.classical)
            .field("pqc", &self.pqc)
            .finish()
    }
}

impl HybridKemCiphertext {
    /// Hybrid ciphertext length in bytes (`32 + 1088 = 1120`).
    pub const LEN: usize = sizes::CIPHERTEXT_LEN;

    /// Construct from a 1 120-byte buffer in IETF X25519MLKEM768 wire
    /// format: `eph_pk_x25519 || ct_mlkem`.
    #[must_use]
    pub fn from_bytes(bytes: &[u8; Self::LEN]) -> Self {
        let mut x25519_bytes = [0_u8; 32];
        x25519_bytes.copy_from_slice(&bytes[0..32]);
        let classical = X25519PublicKey::from_bytes(x25519_bytes);

        let mut mlkem_bytes = [0_u8; sizes::MLKEM_CIPHERTEXT_LEN];
        mlkem_bytes.copy_from_slice(&bytes[32..]);
        let pqc = MlKem768Ciphertext::from_bytes(mlkem_bytes);

        Self { classical, pqc }
    }

    /// Construct from individual ephemeral X25519 public key + ML-KEM
    /// ciphertext.
    #[must_use]
    pub const fn from_parts(classical: X25519PublicKey, pqc: MlKem768Ciphertext) -> Self {
        Self { classical, pqc }
    }

    /// Serialize to the IETF X25519MLKEM768 wire format.
    #[must_use]
    pub fn to_bytes(&self) -> [u8; Self::LEN] {
        let mut out = [0_u8; Self::LEN];
        out[0..32].copy_from_slice(self.classical.as_bytes());
        out[32..].copy_from_slice(self.pqc.as_bytes());
        out
    }

    /// Return the X25519 ephemeral share.
    #[must_use]
    pub const fn classical(&self) -> &X25519PublicKey {
        &self.classical
    }

    /// Return the ML-KEM-768 ciphertext share.
    #[must_use]
    pub const fn pqc(&self) -> &MlKem768Ciphertext {
        &self.pqc
    }
}

/// Hybrid KEM keypair — bundles an X25519 + ML-KEM-768 keypair pair
/// produced by a single keypair-generation flow.
pub struct HybridKemKeyPair {
    public_key: HybridKemPublicKey,
    private_key: HybridKemPrivateKey,
}

impl core::fmt::Debug for HybridKemKeyPair {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HybridKemKeyPair")
            .field("public_key", &self.public_key)
            .field("private_key", &self.private_key)
            .finish()
    }
}

impl HybridKemKeyPair {
    /// Generate a fresh hybrid keypair using the OS CSPRNG.
    ///
    /// Independently generates an X25519 keypair (32-byte random
    /// scalar) and an ML-KEM-768 keypair (64-byte CSPRNG seed). The
    /// two keypairs share no entropy — keypair-isolation invariant
    /// from Decision 2.58.
    ///
    /// # Errors
    ///
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error
    ///   during either constituent keypair generation.
    pub fn try_generate() -> Result<Self> {
        let classical_secret = X25519PrivateKey::try_generate()?;
        let classical_public = classical_secret.public_key();

        let mlkem_keypair = MlKem768KeyPair::try_generate()?;
        let (mlkem_public, mlkem_private) = mlkem_keypair.into_parts();

        Ok(Self {
            public_key: HybridKemPublicKey {
                classical: classical_public,
                pqc: mlkem_public,
            },
            private_key: HybridKemPrivateKey {
                classical: classical_secret,
                pqc: mlkem_private,
            },
        })
    }

    /// Return a reference to the hybrid public key.
    #[must_use]
    pub const fn public_key(&self) -> &HybridKemPublicKey {
        &self.public_key
    }

    /// Return a reference to the hybrid private key.
    #[must_use]
    pub const fn private_key(&self) -> &HybridKemPrivateKey {
        &self.private_key
    }

    /// Decompose the keypair into its constituent public + private
    /// keys, transferring ownership.
    #[must_use]
    pub fn into_parts(self) -> (HybridKemPublicKey, HybridKemPrivateKey) {
        (self.public_key, self.private_key)
    }
}

/// Expected length of the X25519 shared-secret share fed into the
/// hybrid combiner (RFC 7748 § 5).
const X25519_SHARED_LEN: usize = 32;

/// Expected length of the ML-KEM-768 shared-secret share fed into
/// the hybrid combiner (FIPS 203 § 6.1).
const MLKEM_SHARED_LEN: usize = 32;

/// Combine two 32-byte shared secrets into a 64-byte HKDF input
/// keying material wrapped in [`SecretBox<[u8]>`]. Order is
/// `ss_classical || ss_pqc` per the IETF X25519MLKEM768 convention.
///
/// Validates input lengths against the spec-fixed
/// [`X25519_SHARED_LEN`] / [`MLKEM_SHARED_LEN`] constants — a future
/// refactor that accidentally passes wrong-length material fails fast
/// with [`Error::InvalidKeyLength`] instead of silently deriving a
/// different shared secret. In normal operation both inputs always
/// satisfy the length invariant by construction (HACL\* X25519 DH
/// always returns 32 bytes; libcrux ML-KEM-768 decap always returns
/// 32 bytes), so the check is defence-in-depth, not load-bearing on
/// the happy path.
///
/// # Errors
///
/// - [`Error::InvalidKeyLength`] — `ss_classical.len() != 32` or
///   `ss_pqc.len() != 32`.
fn combine_ikm(ss_classical: &[u8], ss_pqc: &[u8]) -> Result<SecretBox<[u8]>> {
    if ss_classical.len() != X25519_SHARED_LEN {
        return Err(Error::InvalidKeyLength {
            expected: X25519_SHARED_LEN,
            actual: ss_classical.len(),
        });
    }
    if ss_pqc.len() != MLKEM_SHARED_LEN {
        return Err(Error::InvalidKeyLength {
            expected: MLKEM_SHARED_LEN,
            actual: ss_pqc.len(),
        });
    }
    let mut combined = Vec::with_capacity(ss_classical.len() + ss_pqc.len());
    combined.extend_from_slice(ss_classical);
    combined.extend_from_slice(ss_pqc);
    Ok(SecretBox::new(combined.into_boxed_slice()))
}
