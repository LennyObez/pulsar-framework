//! Cryptographic hash primitives — safe Rust wrappers over HACL\* via FFI.
//!
//! Per Decision 2.53 + 2.60 + ADR-0009, this module wraps the EverCrypt
//! agile hash dispatcher with typed inputs/outputs. Every public function
//! computes its digest in constant time relative to the input length (per
//! HACL\*'s F\* secret-independence proofs); only message-length leakage
//! through total runtime is unavoidable.
//!
//! Two API surfaces:
//!
//! - **One-shot** — [`hash`] for in-memory data; [`sha256`], [`sha384`], etc.
//!   convenience aliases for the common case. Returns a fresh `Vec<u8>`
//!   sized to the algorithm's digest length.
//! - **Streaming** — [`Hasher`] for chunked input where the full message
//!   doesn't fit in memory. The state is heap-allocated by HACL\* and
//!   freed in `Drop`.
//!
//! Algorithm coverage:
//!
//! | Algorithm | Digest length | FIPS / RFC |
//! |-----------|---------------|------------|
//! | SHA-2-256 | 32 bytes | FIPS 180-4 |
//! | SHA-2-384 | 48 bytes | FIPS 180-4 |
//! | SHA-2-512 | 64 bytes | FIPS 180-4 |
//! | SHA-3-256 | 32 bytes | FIPS 202   |
//! | SHA-3-384 | 48 bytes | FIPS 202   |
//! | SHA-3-512 | 64 bytes | FIPS 202   |
//! | BLAKE2b-256 | 32 bytes | RFC 7693 |
//! | BLAKE2s-256 | 32 bytes | RFC 7693 |
//!
//! MD5 + SHA-1 are deliberately absent from [`HashAlgorithm`]. The C-level
//! symbols for these deprecated hashes are linked into the static archive
//! to satisfy EverCrypt's runtime dispatcher (per ADR-0009 amendment), but
//! the public Rust API rejects them at the type system level — there is
//! no [`HashAlgorithm`] variant for either, so callers cannot construct
//! a request that would invoke them.

// `unsafe_code` is allowed only in this module (the FFI boundary). Each
// `unsafe` block carries a SAFETY comment documenting its pre/post-conditions
// against the upstream HACL* contract. Other pulsar-kernel modules
// (audit, session, router, middleware) inherit the crate-level
// `unsafe_code = deny` — none of them touch FFI directly.
#![allow(unsafe_code)]

use crate::error::{Error, Result};
use core::ptr::NonNull;
use pulsar_crypto_hacl_bindings::ffi;
use std::sync::Once;

/// Initialise EverCrypt's runtime CPU-feature dispatcher exactly once
/// per process. HACL\* docs guarantee idempotence on repeated calls;
/// `Once` avoids the redundant atomic synchronisation cost on the
/// hot path.
///
/// HACL\* contract reference:
/// `crates/pulsar-crypto-hacl-bindings/hacl-c/include/EverCrypt_AutoConfig2.h`
/// declares `EverCrypt_AutoConfig2_init` as the canonical initialiser;
/// the upstream HACL\* test harness (`hacl-star/tests/`) and Mozilla NSS
/// production usage both rely on the documented idempotence.
static AUTOCONFIG_INIT: Once = Once::new();

fn ensure_initialized() {
    AUTOCONFIG_INIT.call_once(|| {
        // SAFETY: `EverCrypt_AutoConfig2_init` takes no arguments,
        // returns void, and has no caller-side state to clean up. HACL\*
        // documents it as safe to call multiple times — `Once` enforces
        // the "exactly once" semantic without runtime cost on subsequent
        // hash invocations.
        unsafe { ffi::EverCrypt_AutoConfig2_init() };
    });
}

/// Cryptographic hash algorithms supported by `pulsar-kernel::crypto`.
///
/// Deprecated algorithms (MD5, SHA-1) are intentionally omitted — the
/// HACL\* dispatcher links their symbols but no public API path can reach
/// them through this enum. Subsequent sprint phases extend the variant
/// set; existing variants are stable.
#[non_exhaustive]
#[derive(Debug, Copy, Clone, Eq, PartialEq, Hash)]
pub enum HashAlgorithm {
    /// SHA-2-256 per FIPS 180-4. 32-byte digest. Most common general-purpose hash.
    Sha256,
    /// SHA-2-384 per FIPS 180-4. 48-byte digest. Recommended for FIPS 140-3 modules.
    Sha384,
    /// SHA-2-512 per FIPS 180-4. 64-byte digest.
    Sha512,
    /// SHA-3-256 per FIPS 202. 32-byte digest. Algorithmically distinct from SHA-2.
    Sha3_256,
    /// SHA-3-384 per FIPS 202. 48-byte digest.
    Sha3_384,
    /// SHA-3-512 per FIPS 202. 64-byte digest.
    Sha3_512,
    /// BLAKE2b per RFC 7693. 64-byte digest (the algorithm's default
    /// output size; output truncation is not exposed via this enum).
    /// Faster than SHA-2-512 on 64-bit hosts.
    Blake2b512,
    /// BLAKE2s per RFC 7693. 32-byte digest (the algorithm's default
    /// output size). Optimised for 32-bit hosts.
    Blake2s256,
}

impl HashAlgorithm {
    /// Digest output length in bytes for this algorithm.
    #[must_use]
    pub const fn digest_len(self) -> usize {
        match self {
            Self::Sha256 | Self::Sha3_256 | Self::Blake2s256 => 32,
            Self::Sha384 | Self::Sha3_384 => 48,
            Self::Sha512 | Self::Sha3_512 | Self::Blake2b512 => 64,
        }
    }

    /// Map to the HACL\* / EverCrypt algorithm-id byte.
    ///
    /// Returns the bindgen-generated `Spec_Hash_Definitions_hash_alg`
    /// type alias rather than a raw `u8` so any future change to the
    /// underlying typedef in `Hacl_Spec.h` propagates as a Rust-level
    /// type mismatch rather than silently produce wrong values.
    ///
    /// Reference: `crates/pulsar-crypto-hacl-bindings/hacl-c/include/Hacl_Spec.h`:
    /// ```c
    /// #define Spec_Hash_Definitions_SHA2_256 1
    /// #define Spec_Hash_Definitions_SHA2_384 2
    /// #define Spec_Hash_Definitions_SHA2_512 3
    /// #define Spec_Hash_Definitions_Blake2S  6
    /// #define Spec_Hash_Definitions_Blake2B  7
    /// #define Spec_Hash_Definitions_SHA3_256 8
    /// #define Spec_Hash_Definitions_SHA3_384 10
    /// #define Spec_Hash_Definitions_SHA3_512 11
    /// ```
    /// SHA-2-224, SHA-3-224, SHA-1, MD5 are not exposed via this enum;
    /// pulsar-kernel never accepts them via its public surface.
    const fn to_ffi(self) -> ffi::Spec_Hash_Definitions_hash_alg {
        match self {
            Self::Sha256 => 1,
            Self::Sha384 => 2,
            Self::Sha512 => 3,
            Self::Blake2s256 => 6,
            Self::Blake2b512 => 7,
            Self::Sha3_256 => 8,
            Self::Sha3_384 => 10,
            Self::Sha3_512 => 11,
        }
    }
}

/// Compute the cryptographic hash of `input` into a caller-provided
/// buffer.
///
/// The buffer must be at least [`HashAlgorithm::digest_len`] bytes
/// long; only that prefix is written. Used internally by both [`hash`]
/// (which allocates a `Vec<u8>`) and the algorithm-specific convenience
/// aliases (which write directly into a stack-allocated array). Keeps
/// the FFI invocation in a single place so soundness arguments need
/// only be made once.
///
/// # Errors
///
/// - [`Error::InvalidOutputLength`] — `output.len() < algo.digest_len()`.
/// - [`Error::InputTooLong`] — `input.len() > u32::MAX`.
fn hash_into_slice(algo: HashAlgorithm, input: &[u8], output: &mut [u8]) -> Result<()> {
    if output.len() < algo.digest_len() {
        return Err(Error::InvalidOutputLength {
            expected: algo.digest_len(),
            actual: output.len(),
        });
    }

    ensure_initialized();

    let len = u32::try_from(input.len()).map_err(|_| Error::InputTooLong {
        actual: input.len(),
        max: u32::MAX as usize,
    })?;

    // SAFETY: EverCrypt_Hash_Incremental_hash preconditions:
    //   - `a` is a valid Spec_Hash_Definitions_hash_alg constant — guaranteed
    //     by HashAlgorithm::to_ffi enumerating only modern hashes.
    //   - `output` points to at least `digest_len` writable bytes — checked
    //     against output.len() at function entry above.
    //   - `input` may be a dangling-but-non-null pointer when input_len = 0
    //     per HACL*'s contract; HACL* does not dereference when len = 0.
    //     `<[u8]>::as_ptr` returns a non-null pointer for empty slices
    //     (per Rust language guarantees) and a valid pointer otherwise.
    //   - `input_len` fits in u32 — checked above.
    // The cast from `*const u8` to `*mut u8` is sound: HACL* documents
    // the input as read-only despite the C signature lacking `const`.
    unsafe {
        ffi::EverCrypt_Hash_Incremental_hash(
            algo.to_ffi(),
            output.as_mut_ptr(),
            input.as_ptr().cast_mut(),
            len,
        );
    }

    Ok(())
}

/// Compute the cryptographic hash of `input` using `algo` in one shot.
///
/// Returns a fresh `Vec<u8>` of length [`HashAlgorithm::digest_len`].
/// Constant-time relative to message contents; total runtime depends
/// on `input.len()` linearly (per HACL\*'s F\* proofs of secret
/// independence — message length is public).
///
/// For algorithm-specific call sites that already know the digest size
/// at compile time, the convenience aliases ([`sha256`], [`sha384`],
/// etc.) avoid the heap allocation.
///
/// # Errors
///
/// - [`Error::InputTooLong`] — `input.len() > u32::MAX`. HACL\*'s
///   single-shot API takes a 32-bit length parameter; 4 GiB exceeds
///   any plausible Pulsar payload size.
pub fn hash(algo: HashAlgorithm, input: &[u8]) -> Result<Vec<u8>> {
    let mut output = vec![0_u8; algo.digest_len()];
    hash_into_slice(algo, input, &mut output)?;
    Ok(output)
}

/// Streaming hash computation for chunked input.
///
/// Use [`Hasher::new`] to allocate state, [`Hasher::update`] to feed
/// bytes incrementally, and [`Hasher::finalize`] to produce the digest.
/// The HACL\*-side state is freed automatically when the [`Hasher`] is
/// dropped (whether normally or via panic-unwind).
///
/// Multiple `Hasher` instances on different threads can run in parallel
/// — each owns its own state. Sharing a single `Hasher` across threads
/// is forbidden by the borrow checker (`update` takes `&mut self`).
pub struct Hasher {
    algo: HashAlgorithm,
    state: NonNull<ffi::EverCrypt_Hash_Incremental_state_t>,
}

// SAFETY: Hasher owns its FFI state pointer exclusively. No internal
// shared mutability; `update` takes `&mut self` so the borrow checker
// prevents concurrent access. HACL*'s incremental hash state is not
// thread-safe per-instance (would require a lock), but Send is fine
// because ownership transfer is single-threaded by definition.
unsafe impl Send for Hasher {}

impl core::fmt::Debug for Hasher {
    /// Print only the algorithm — the FFI state pointer is uninteresting
    /// to debug consumers and would expose internal layout details that
    /// belong on the audit boundary, not in formatted output.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("Hasher")
            .field("algorithm", &self.algo)
            .finish_non_exhaustive()
    }
}

impl Hasher {
    /// Allocate a new hasher state for the chosen algorithm.
    ///
    /// # Errors
    ///
    /// - [`Error::StateAllocationFailed`] — HACL\*'s `*_malloc` returned NULL
    ///   (out-of-memory at the C layer). Should not occur under normal load.
    pub fn new(algo: HashAlgorithm) -> Result<Self> {
        ensure_initialized();

        // SAFETY: EverCrypt_Hash_Incremental_malloc takes a valid
        // Spec_Hash_Definitions_hash_alg constant and returns a pointer
        // to caller-owned state (or NULL on allocation failure). The
        // returned pointer is non-aliased and exclusively owned by the
        // calling Hasher instance.
        let raw = unsafe { ffi::EverCrypt_Hash_Incremental_malloc(algo.to_ffi()) };
        let state = NonNull::new(raw).ok_or(Error::StateAllocationFailed)?;
        Ok(Self { algo, state })
    }

    /// Algorithm this hasher was constructed for.
    #[must_use]
    pub const fn algorithm(&self) -> HashAlgorithm {
        self.algo
    }

    /// Feed `chunk` into the hash state. May be called multiple times
    /// before [`Hasher::finalize`].
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `chunk.len() > u32::MAX` for a single
    ///   call. Larger inputs must be split across multiple `update`
    ///   calls (each chunk ≤ 4 GiB).
    /// - [`Error::Hacl`] — HACL\* returned a non-success status code
    ///   (e.g., the cumulative input exceeded the algorithm's
    ///   per-message limit: 2^61-1 bytes for SHA-2-256, 2^64-1 bytes
    ///   for SHA-2-384/512 + SHA-3 + BLAKE2 — practically unreachable).
    pub fn update(&mut self, chunk: &[u8]) -> Result<()> {
        let len = u32::try_from(chunk.len()).map_err(|_| Error::InputTooLong {
            actual: chunk.len(),
            max: u32::MAX as usize,
        })?;

        // SAFETY: EverCrypt_Hash_Incremental_update preconditions:
        //   - `state` is valid (held in NonNull, freed only in Drop).
        //   - `chunk` may be a dangling-non-null pointer when len = 0.
        //   - `chunk_len` fits in u32 — checked above.
        // Returns 0 on success; any non-zero is mapped to Error::Hacl.
        let rc = unsafe {
            ffi::EverCrypt_Hash_Incremental_update(
                self.state.as_ptr(),
                chunk.as_ptr().cast_mut(),
                len,
            )
        };

        if rc == 0 {
            Ok(())
        } else {
            // EverCrypt_Error_MaximumLengthExceeded = 2 (per
            // hacl-c/include/EverCrypt_Error.h). The only realistic
            // non-success status from `_update` for the hash dispatcher
            // is the cumulative-length overflow; other EverCrypt error
            // codes (UnsupportedAlgorithm = 1, InvalidKey = 3,
            // AuthenticationFailure = 4, InvalidIVLength = 5) cannot
            // arise on this code path because the algorithm parameter
            // was validated at `Hasher::new` time and the API takes no
            // key/IV inputs. A future binding-crate error-mapping
            // refresh can map specific codes to typed variants; for
            // now, any non-zero status is treated as a length overflow.
            Err(Error::HashInputLimitExceeded)
        }
    }

    /// Compute the digest from the current state without consuming the
    /// hasher.
    ///
    /// HACL\*'s `digest` operation operates on an internal copy of the
    /// state (per the C contract documented in `EverCrypt_Hash.h`), so
    /// subsequent [`Hasher::update`] calls continue from the
    /// pre-finalize state. Useful for transcript-hashing patterns where
    /// a provisional digest is needed before more bytes arrive (TLS
    /// handshake transcripts, Merkle-tree intermediate roots, etc.).
    #[must_use]
    pub fn digest(&self) -> Vec<u8> {
        let mut output = vec![0_u8; self.algo.digest_len()];

        // SAFETY: EverCrypt_Hash_Incremental_digest preconditions:
        //   - `state` is valid (held in NonNull until Drop).
        //   - `output` points to at least digest_len writable bytes —
        //     freshly allocated above to that exact length.
        // The C function does not modify the state externally
        // observable; safe to call repeatedly.
        unsafe {
            ffi::EverCrypt_Hash_Incremental_digest(self.state.as_ptr(), output.as_mut_ptr());
        }
        output
    }

    /// Compute the digest and consume the hasher.
    ///
    /// Equivalent to [`Hasher::digest`] followed by drop. Use this when
    /// no further updates are needed — the consumed `self` makes the
    /// no-further-use intent explicit at the call site.
    #[must_use]
    pub fn finalize(self) -> Vec<u8> {
        self.digest()
    }

    /// Reset the hasher state to the initial empty-input position
    /// without re-allocating the FFI state.
    ///
    /// Useful for long-running services that hash many messages with the
    /// same algorithm — `reset` avoids the per-message
    /// `EverCrypt_Hash_Incremental_malloc + _free` allocation cycle. The
    /// algorithm is preserved across resets.
    pub fn reset(&mut self) {
        // SAFETY: `state` is valid (held in NonNull throughout the
        // Hasher lifetime). EverCrypt_Hash_Incremental_reset does not
        // free or reallocate; it re-initialises the state buffer in
        // place per the C contract documented in EverCrypt_Hash.h.
        unsafe { ffi::EverCrypt_Hash_Incremental_reset(self.state.as_ptr()) };
    }
}

impl Drop for Hasher {
    fn drop(&mut self) {
        // SAFETY: `state` is valid (held in NonNull throughout the
        // Hasher lifetime). EverCrypt_Hash_Incremental_free is the
        // matching deallocator for EverCrypt_Hash_Incremental_malloc.
        unsafe { ffi::EverCrypt_Hash_Incremental_free(self.state.as_ptr()) };
    }
}

// ──────────────────────────────────────────────────────────────────────
// Algorithm-specific convenience aliases — one per supported hash.
// Each performs the one-shot computation with a fixed algorithm and
// returns a stack-allocated fixed-size array. Implemented via
// `hash_into_slice` so no `Vec<u8>` is allocated on the hot path —
// preferred over `hash()` for performance-sensitive call sites where
// the digest size is known at compile time.
// ──────────────────────────────────────────────────────────────────────

/// Internal helper: hash to a stack-allocated fixed-size array.
///
/// `N` must equal `algo.digest_len()` — the convenience aliases below
/// pass the correct (algorithm, N) pair so the `InvalidOutputLength`
/// error path is unreachable from these call sites.
fn hash_to_array<const N: usize>(algo: HashAlgorithm, input: &[u8]) -> Result<[u8; N]> {
    debug_assert_eq!(
        N,
        algo.digest_len(),
        "convenience alias requires N == algo.digest_len(); this is a bug if it fires",
    );
    let mut output = [0_u8; N];
    hash_into_slice(algo, input, &mut output)?;
    Ok(output)
}

/// SHA-2-256 of `input` per FIPS 180-4. 32-byte digest. Convenience
/// alias for `hash(HashAlgorithm::Sha256, input)` that avoids the
/// `Vec<u8>` heap allocation by writing directly into a stack array.
///
/// # Errors
///
/// See [`hash`].
pub fn sha256(input: &[u8]) -> Result<[u8; 32]> {
    hash_to_array(HashAlgorithm::Sha256, input)
}

/// SHA-2-384 of `input` per FIPS 180-4. 48-byte digest.
///
/// # Errors
///
/// See [`hash`].
pub fn sha384(input: &[u8]) -> Result<[u8; 48]> {
    hash_to_array(HashAlgorithm::Sha384, input)
}

/// SHA-2-512 of `input` per FIPS 180-4. 64-byte digest.
///
/// # Errors
///
/// See [`hash`].
pub fn sha512(input: &[u8]) -> Result<[u8; 64]> {
    hash_to_array(HashAlgorithm::Sha512, input)
}

/// SHA-3-256 of `input` per FIPS 202. 32-byte digest.
///
/// # Errors
///
/// See [`hash`].
pub fn sha3_256(input: &[u8]) -> Result<[u8; 32]> {
    hash_to_array(HashAlgorithm::Sha3_256, input)
}

/// SHA-3-384 of `input` per FIPS 202. 48-byte digest.
///
/// # Errors
///
/// See [`hash`].
pub fn sha3_384(input: &[u8]) -> Result<[u8; 48]> {
    hash_to_array(HashAlgorithm::Sha3_384, input)
}

/// SHA-3-512 of `input` per FIPS 202. 64-byte digest.
///
/// # Errors
///
/// See [`hash`].
pub fn sha3_512(input: &[u8]) -> Result<[u8; 64]> {
    hash_to_array(HashAlgorithm::Sha3_512, input)
}

/// BLAKE2b of `input` per RFC 7693. 64-byte digest (the algorithm's
/// default output size).
///
/// # Errors
///
/// See [`hash`].
pub fn blake2b512(input: &[u8]) -> Result<[u8; 64]> {
    hash_to_array(HashAlgorithm::Blake2b512, input)
}

/// BLAKE2s-256 of `input` per RFC 7693. 32-byte digest.
///
/// # Errors
///
/// See [`hash`].
pub fn blake2s256(input: &[u8]) -> Result<[u8; 32]> {
    hash_to_array(HashAlgorithm::Blake2s256, input)
}
