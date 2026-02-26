# ADR-0006: Libsodium-Only Cryptography with Master Key Derivation

## Status

Accepted

## Context

PHP applications typically depend on external cryptography packages (`defuse/php-encryption`, `paragonie/halite`, `phpseclib`) or use low-level OpenSSL bindings directly. These approaches introduce:

- **Dependency risk.** External packages must be tracked for CVEs, version-constrained, and audited. Supply chain attacks on crypto libraries have severe consequences.
- **Algorithm choice complexity.** OpenSSL exposes dozens of cipher suites. Developers must choose correctly - and many don't.
- **Key management fragmentation.** Each subsystem (sessions, CSRF, encryption, audit) often manages its own key material, leading to key sprawl and inconsistent rotation.

PHP 7.2+ bundles libsodium (`ext-sodium`) as a core extension. Libsodium provides a small, opinionated API with safe defaults: XSalsa20-Poly1305 for authenticated encryption, BLAKE2b for hashing, X25519 for key exchange, and Argon2id for password hashing.

## Decision

All cryptographic operations in Pulsar use PHP's bundled libsodium. No external cryptography packages are permitted.

### Primitives

- **Authenticated encryption (general):** `sodium_crypto_secretbox` (XSalsa20-Poly1305) via `Encryptor`.
- **Authenticated encryption (session):** `sodium_crypto_aead_xchacha20poly1305_ietf` (XChaCha20-Poly1305 AEAD) via `SessionEncryption`, with Additional Authenticated Data binding session ID, handler type, and domain.
- **Keyed hashing:** BLAKE2b via `sodium_crypto_generichash` with a key parameter (not RFC 2104 HMAC construction).
- **Key derivation:** `sodium_crypto_kdf_derive_from_key` for domain-separated subkeys.
- **Password hashing:** Argon2id via PHP native `password_hash` (exception: uses PHP's built-in implementation rather than `sodium_crypto_pwhash_str` for upgrade ergonomics via `password_needs_rehash`).

### Master key architecture

A single 32-byte master key is provided via the `PULSAR_MASTER_KEY` environment variable. All subsystem keys are derived from this master key using KDF with domain-separated 8-byte contexts:

- `encrypt_` (subkey ID 1) - general-purpose encryption (`Encryptor`)
- `audit___` (subkey ID 2) - audit log chain integrity
- `mailaudt` (subkey ID 2) - mail audit HMAC (`MailAuditor`)
- `session_` (subkey ID 3) - session AEAD encryption (`SessionEncryption`)
- `pseudo__` (subkey ID 3) - pseudonymization (`PseudonymizationService`)
- `stud_enc` (subkey ID 3) - Studio encryption (via `Encryptor::withDerivedKey`)
- `rcvrycod` (subkey ID 3) - recovery code generation (`AuthWiring`)
- `stud_mac` (subkey ID 4) - Studio archive MAC (`StudioExtension`)
- `stud_chn` (subkey ID 5) - Studio chain link MAC (`StudioExtension`)
- `integ_sg` (subkey ID 6) - file integrity signing (`ManifestSigner`)
- `fw_cache` (subkey ID 7) - cache keyed BLAKE2b (`FrameworkCache`)
- `bld_sign` (subkey ID 8) - build artifact signing (`ArtifactIntegrityVerifier`)
- `que_aead` (subkey ID 10) - queue AEAD payload encryption (`AeadPayloadEncryptor`)
- `anal_vis` (subkey ID 20) - analytics visitor hashing (`AnalyticsKeyManager`)

The `MasterKey` class manages derivation. Context strings must be exactly 8 bytes (per `sodium_crypto_kdf_derive_from_key` requirements); `MasterKey::normalizeContext()` throws `InvalidArgumentException` otherwise. `MasterKey::deriveSubKey()` itself does not cache -- it calls `sodium_crypto_kdf_derive_from_key` on every invocation. Caching happens at the service level: consumers such as `Encryptor`, `SessionEncryption`, and `ManifestSigner` are registered as singletons that derive and store their subkey at construction time, amortizing the KDF cost across the request lifetime.

**Exceptions to the libsodium-only policy:**

- **TOTP verification** uses `hash_hmac('sha1', ...)` per RFC 6238, which mandates SHA-1 HMAC
- **Password hashing** uses PHP's native `password_hash()` with `PASSWORD_ARGON2ID` rather than `sodium_crypto_pwhash_str()`

## Consequences

### Positive

- **Zero external crypto dependencies.** No packages to audit, no supply chain risk for cryptographic primitives.
- **Safe defaults only.** Libsodium's API makes it hard to misuse: nonces are generated automatically, authentication is mandatory, and weak algorithms are not available.
- **Single key to manage.** One environment variable, one rotation procedure. Subkeys are derived deterministically - rotating the master key rotates everything.
- **Domain separation.** Even if one subkey is compromised (e.g., via a cache side channel), other subsystems remain protected. Different KDF contexts produce cryptographically independent keys.

### Negative

- **No algorithm flexibility.** Applications needing specific algorithms (RSA for JWT signing, AES-GCM for compliance mandates) must use external packages. Pulsar's crypto layer does not wrap or expose these.
- **Master key is a single point of failure.** If the master key is compromised, all derived keys are compromised. Mitigation: the key lives only in the environment, never in source code or config files.
- **Libsodium availability.** While bundled since PHP 7.2, some minimal PHP installations may not have `ext-sodium` enabled. Pulsar requires it.

### Neutral

- **Key rotation** requires re-encrypting data encrypted with the old master key. For audit chains, multi-key verification (checking entries against both old and new keys) can be used during the transition window. This is an operational procedure, not a framework limitation.
- **Operational guidance.** `PULSAR_MASTER_KEY` should be stored in a secret manager (Vault, AWS Secrets Manager, etc.) with a documented break-glass procedure for emergency access. The key must never appear in source code, config files, or CI logs. The `key:generate` command produces a cryptographically random key for initial setup.
