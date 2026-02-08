# ADR-0006: Libsodium-Only Cryptography with Master Key Derivation

## Status

Accepted

## Context

PHP applications typically depend on external cryptography packages (`defuse/php-encryption`, `paragonie/halite`, `phpseclib`) or use low-level OpenSSL bindings directly. These approaches introduce:

- **Dependency risk.** External packages must be tracked for CVEs, version-constrained, and audited. Supply chain attacks on crypto libraries have severe consequences.
- **Algorithm choice complexity.** OpenSSL exposes dozens of cipher suites. Developers must choose correctly — and many don't.
- **Key management fragmentation.** Each subsystem (sessions, CSRF, encryption, audit) often manages its own key material, leading to key sprawl and inconsistent rotation.

PHP 7.2+ bundles libsodium (`ext-sodium`) as a core extension. Libsodium provides a small, opinionated API with safe defaults: XSalsa20-Poly1305 for authenticated encryption, BLAKE2b for hashing, X25519 for key exchange, and Argon2id for password hashing.

## Decision

All cryptographic operations in Pulsar use PHP's bundled libsodium. No external cryptography packages are permitted.

### Primitives

- **Authenticated encryption:** `sodium_crypto_secretbox` (XSalsa20-Poly1305).
- **HMAC:** BLAKE2b via `sodium_crypto_generichash`.
- **Key derivation:** `sodium_crypto_kdf_derive_from_key` for domain-separated subkeys.
- **Password hashing:** Argon2id via `sodium_crypto_pwhash_str`.

### Master Key Architecture

A single 32-byte master key is provided via the `PULSAR_MASTER_KEY` environment variable. All subsystem keys are derived from this master key using KDF with domain-separated contexts:

- `pulsar__encryption` — general-purpose encryption
- `pulsar__csrf_hmac` — CSRF token generation
- `pulsar__audit_hmac` — audit log chain integrity
- `pulsar__cache_hmac` — cache entry verification

The `MasterKey` class manages derivation. Subkeys are derived on demand and cached in memory for the request lifetime.

## Consequences

### Positive

- **Zero external crypto dependencies.** No packages to audit, no supply chain risk for cryptographic primitives.
- **Safe defaults only.** Libsodium's API makes it hard to misuse: nonces are generated automatically, authentication is mandatory, and weak algorithms are not available.
- **Single key to manage.** One environment variable, one rotation procedure. Subkeys are derived deterministically — rotating the master key rotates everything.
- **Domain separation.** Even if one subkey is compromised (e.g., via a cache side channel), other subsystems remain protected. Different KDF contexts produce cryptographically independent keys.

### Negative

- **No algorithm flexibility.** Applications needing specific algorithms (RSA for JWT signing, AES-GCM for compliance mandates) must use external packages. Pulsar's crypto layer does not wrap or expose these.
- **Master key is a single point of failure.** If the master key is compromised, all derived keys are compromised. Mitigation: the key lives only in the environment, never in source code or config files.
- **Libsodium availability.** While bundled since PHP 7.2, some minimal PHP installations may not have `ext-sodium` enabled. Pulsar requires it.

### Neutral

- **Key rotation** requires re-encrypting data encrypted with the old master key. For audit chains, multi-key verification (checking entries against both old and new keys) can be used during the transition window. This is an operational procedure, not a framework limitation.
- **Operational guidance.** `PULSAR_MASTER_KEY` should be stored in a secret manager (Vault, AWS Secrets Manager, etc.) with a documented break-glass procedure for emergency access. The key must never appear in source code, config files, or CI logs. The `key:generate` command produces a cryptographically random key for initial setup.
