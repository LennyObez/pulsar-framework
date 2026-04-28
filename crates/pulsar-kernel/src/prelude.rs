//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Downstream code uses `use pulsar_kernel::prelude::*;` to bring the
//! curated public surface into scope. The prelude re-exports the most
//! frequently used items per sub-module — algorithm enums, hash entry
//! points, error type, and the `Result` alias.

pub use crate::crypto::{
    AeadAlgorithm, AeadKey, Argon2idParams, Argon2idVerifyLimits, Ed25519PrivateKey,
    Ed25519PublicKey, Ed25519Signature, HashAlgorithm, Hasher, HmacAlgorithm, HmacKey,
    MlKem768Ciphertext, MlKem768KeyPair, MlKem768PrivateKey, MlKem768PublicKey, X25519PrivateKey,
    X25519PublicKey,
};
pub use crate::error::{Error, Result};
