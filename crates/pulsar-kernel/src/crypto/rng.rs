//! Cryptographically-secure random-byte generation.
//!
//! Pulsar's kernel-level CSPRNG abstraction. Entropy is sourced from
//! the operating system's CSPRNG (`getrandom(2)` on Linux,
//! `BCryptGenRandom` on Windows, `SecRandomCopyBytes` on macOS,
//! `RtlGenRandom` on older Windows fallback) via the [`rand_core`] /
//! [`rand`] ecosystem's [`OsRng`]. The OS interface is the only entropy
//! source Pulsar uses for cryptographic operations — no userspace PRNG
//! is permitted on the cryptographic-key path per the regulated-domain
//! audit posture.
//!
//! # API surface
//!
//! Two fallible entry points cover the typical use cases:
//!
//! - [`try_random_bytes(n)`](try_random_bytes) — allocate a fresh
//!   `Vec<u8>` filled with `n` random bytes.
//! - [`try_random_into(&mut [u8])`](try_random_into) — fill a
//!   caller-provided slice with random bytes (slice-API parity with
//!   the Vec-allocating form, suitable for stack buffers).
//!
//! Both surfaces return [`Result<_>`](Result) — the OS-level call
//! `getrandom(2)` and equivalents can fail under the documented
//! degenerate cases (very early boot before the entropy pool is
//! seeded, exhausted entropy pool on minimal kernels, sandboxes that
//! block the system call). Production code paths should treat
//! [`Error::RngFailure`] as fatal-but-transient and abort the
//! in-flight operation rather than retrying with reduced entropy.
//!
//! Pulsar deliberately does NOT expose an infallible variant
//! ([`rand::RngCore::fill_bytes`] panics on failure) — every kernel
//! API is fallible per the project's "no panics in library code"
//! policy.
//!
//! # Custom RNG injection
//!
//! Callers that already hold a [`rand::CryptoRng`] (e.g., a
//! deterministic test seed via `rand_chacha::ChaCha20Rng`) may use the
//! underlying primitives directly via the re-exports [`CryptoRng`] and
//! [`RngCoreTrait`]. Test infrastructure relies on this for
//! reproducible-failure debugging; production paths should use the
//! concrete `OsRng`-backed helpers above.

use crate::error::{Error, Result};
use rand::RngCore;
use rand::rngs::OsRng;

pub use rand::{CryptoRng, RngCore as RngCoreTrait};

/// Fill a caller-provided slice with cryptographically-secure random
/// bytes from the OS CSPRNG.
///
/// # Errors
///
/// - [`Error::RngFailure`] — the OS CSPRNG returned an error (early
///   boot, exhausted entropy pool, sandboxed environment blocking the
///   system call). Treat as fatal-but-transient.
pub fn try_random_into(buf: &mut [u8]) -> Result<()> {
    OsRng.try_fill_bytes(buf).map_err(|_| Error::RngFailure)
}

/// Allocate a `Vec<u8>` of length `n` filled with cryptographically-
/// secure random bytes from the OS CSPRNG.
///
/// Equivalent to allocating a zeroed `Vec<u8>` of length `n` and
/// calling [`try_random_into`] on it. Callers that own a stack buffer
/// of known length should prefer [`try_random_into`] to avoid the
/// heap allocation.
///
/// # Errors
///
/// - [`Error::RngFailure`] — the OS CSPRNG returned an error.
pub fn try_random_bytes(n: usize) -> Result<Vec<u8>> {
    let mut buf = vec![0_u8; n];
    try_random_into(&mut buf)?;
    Ok(buf)
}
