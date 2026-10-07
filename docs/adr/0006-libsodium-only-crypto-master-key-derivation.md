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

A single 32-byte master key is provided via the `PULSAR_MASTER_KEY` environment variable. All subsystem keys are derived from this master key using KDF with domain-separated 8-byte contexts.

**The unit of domain separation is the `(id, context)` pair.** Ids are shared across distinct contexts on purpose -- `sodium_crypto_kdf_derive_from_key` mixes both into the derivation, so `(3, 'session_')` and `(3, 'pseudo__')` are unrelated keys. A shared _pair_ is the defect: two subsystems deriving the same `(id, context)` hold the same key, and whichever of them publishes its output becomes an oracle for whatever the other one seals. That is not hypothetical -- `(9, 'app_cobs')` keyed both the encrypted cache's AAD binding tag and the cache-key digest emitted into log lines, until the observability side was moved to `(21, 'app_kobs')`.

Allocating a key means allocating a pair no other subsystem uses, and recording it in two places: the table below, and the assignment table in `tests/Unit/Security/Crypto/SubKeyIdRegistryTest.php`. That test is the gate. It walks every `deriveSubKey()` and `sodium_crypto_kdf_derive_from_key()` call site under `src/` and `extensions/`, resolves the literal pair at each, and fails the build on a pair derived by a subsystem that does not own it, on a pair no entry declares, and on a declared pair nothing derives. This table is what a reviewer reads before allocating; that test is what stops it drifting again, which it had -- it listed 15 pairs while 39 were live.

| Id  | Context               | Purpose                                         | Owner                                                      |
| --- | --------------------- | ----------------------------------------------- | ---------------------------------------------------------- |
| 1   | `encrypt_`            | General-purpose encryption                      | `Encryptor`                                                |
| 2   | `audit___`            | Audit log chain HMAC                            | `SecurityWiring`                                           |
| 2   | `mailaudt`            | Mail audit HMAC                                 | `MailAuditor`                                              |
| 3   | `pseudo__`            | Pseudonymisation                                | `PseudonymizationService`                                  |
| 3   | `rcvrycod`            | Two-factor recovery code generation             | `AuthWiring`                                               |
| 3   | `session_`            | Session AEAD encryption                         | `SessionEncryption`                                        |
| 3   | `stud_enc`            | Studio encryptor                                | `StudioExtension` (and `extensions/studio/dev/router.php`) |
| 4   | `sess_fp_`            | Session fingerprint validator MAC               | `SecurityWiring`                                           |
| 4   | `stud_mac`            | Studio archive MAC                              | `StudioExtension`                                          |
| 4   | `totpscrt`            | TOTP secret encryption                          | `AuthWiring`                                               |
| 5   | `orm__enc`            | ORM encrypted-column default key                | `EncryptionConfig` (ORM extension)                         |
| 5   | `stud_chn`            | Studio chain link MAC                           | `StudioExtension`                                          |
| 6   | `integ_sg`            | File integrity manifest signing                 | `ManifestSigner`                                           |
| 7   | `fw_cache`            | Framework cache keyed BLAKE2b                   | `FrameworkCache`                                           |
| 7   | `tokenize`            | Tokenisation service encryption                 | `TokenizationService`                                      |
| 8   | `app_cenc`            | Application cache value encryption              | `EncryptedCacheDecorator`                                  |
| 8   | `bld_sign`            | Build artifact signing                          | `ArtifactIntegrityVerifier`                                |
| 9   | `app_cobs`            | Application cache AAD binding MAC               | `EncryptedCacheDecorator`                                  |
| 10  | `cms_prev`            | CMS preview token signing                       | `CmsKeyManager`                                            |
| 10  | `que_aead`            | Queue AEAD payload encryption                   | `AeadPayloadEncryptor`                                     |
| 11  | `cms_mdia`            | CMS signed media URL signing                    | `CmsKeyManager`                                            |
| 12  | `cms_xprt`            | CMS export archive encryption                   | `CmsKeyManager`                                            |
| 12  | `idemcach`            | Idempotency cache HMAC envelope                 | `SignedIdempotencyEnvelope`                                |
| 13  | `cms_dwnl`            | CMS download token signing                      | `CmsKeyManager`                                            |
| 13  | _computed at runtime_ | ORM blind-index keyed hashing                   | `AttributeColumnEncryptor` (ORM extension)                 |
| 14  | `cms_evid`            | CMS order-export evidence hashing               | `CmsKeyManager`                                            |
| 14  | `psd2_sca`            | PSD2 SCA dynamic-linking MAC                    | `Psd2ServiceProvider`                                      |
| 15  | `cms_apik`            | CMS API-key HMAC pepper                         | `CmsKeyManager`                                            |
| 15  | `cmplprb1`            | Compliance key-derivation probe                 | `RuntimeVerifier`                                          |
| 15  | `cmplprb2`            | Compliance key-derivation probe, separation arm | `RuntimeVerifier`                                          |
| 15  | `secrets_`            | Secret vault encryption                         | `SecretVault`                                              |
| 15  | `stu_seed`            | Studio evidence hash-chain seed                 | `HashChain` (Studio extension)                             |
| 16  | `antispam`            | Managed challenge captcha signing               | `AntiSpamWiring`                                           |
| 17  | `antispam`            | Time-trap anti-spam signing                     | `AntiSpamWiring`                                           |
| 18  | `cmp_logs`            | Compliance log pseudonymisation                 | `ComplianceLoggingWiring`                                  |
| 19  | `oa2_code`            | OAuth2 authorization-code hashing               | `DbAuthorizationCodeRepository`                            |
| 20  | `anal_vis`            | Analytics visitor hashing                       | `AnalyticsKeyManager`                                      |
| 20  | `cmp_evid`            | Compliance evidence chain HMAC                  | `ComplianceVerificationWiring`                             |
| 21  | `app_kobs`            | Application cache key hashing for observability | `CacheManager`                                             |
| 22  | `bkup_arc`            | Sealed backup archive AEAD                      | `ArchiveSeal`                                              |

Forty pairs are live. Thirty-eight are literal at their call site and are the ones `SubKeyIdRegistryTest` enforces; the two marked above are not, and are the reviewer's responsibility rather than the gate's. `(5, 'orm__enc')` is a configured default a deployment may override in `config/orm.php`, and the ORM blind index at id 13 hashes its public label down to eight bytes at derivation time, so neither pair exists as a literal for a static scan to read. Both ids are nonetheless taken, and a new subsystem may take 5 or 13 only under a context the ORM cannot produce.

Ids 1-12 predate this registry and are shared. New first-party allocations take the next free id, and `SubKeyId` records for every case both what allocated it and what else is live on it -- a case is never repurposed, because changing a subsystem's pair re-keys it and everything it has already sealed becomes unreadable.

The `MasterKey` class manages derivation. Context strings must be exactly 8 bytes (per `sodium_crypto_kdf_derive_from_key` requirements); `MasterKey::normalizeContext()` throws `InvalidArgumentException` otherwise. `MasterKey::deriveSubKey()` itself does not cache -- it calls `sodium_crypto_kdf_derive_from_key` on every invocation. Caching happens at the service level: consumers such as `Encryptor`, `SessionEncryption`, and `ManifestSigner` are registered as singletons that derive and store their subkey at construction time, amortizing the KDF cost across the request lifetime.

**Exceptions to the libsodium-only policy:**

- **TOTP verification** uses `hash_hmac('sha1', ...)` per RFC 6238, which mandates SHA-1 HMAC
- **Password hashing** uses PHP's native `password_hash()` with `PASSWORD_ARGON2ID` rather than `sodium_crypto_pwhash_str()`
- **FIPS 140-2 compatible cipher suite.** `AesGcmCipherSuite` uses `sodium_crypto_aead_aes256gcm_encrypt` / `sodium_crypto_aead_aes256gcm_decrypt` (libsodium's native AES-256-GCM with hardware AES-NI acceleration). This is FIPS-approved AES-256-GCM while remaining within the libsodium-only policy -- no ADR exception required for the primary path. Full FIPS 140-2/140-3 compliance requires deploying PHP with a NIST-validated crypto provider (e.g., OpenSSL 3.x FIPS module).

  That suite's OpenSSL `aes-256-gcm` fallback is the exception, and it is scoped to two cases: a CPU where `sodium_crypto_aead_aes256gcm_is_available()` returns `false` (libsodium refuses to do AES-GCM at all there, so the alternative is no AES-GCM), and a deployment that sets `PULSAR_CRYPTO_FORCE_OPENSSL` to `1` or `true` to route the cipher through its validated provider instead of libsodium's implementation. The constructor reads that variable through `Environment::read()` (ADR-0033) and refuses to construct when the OpenSSL build offers no `aes-256-gcm`. The algorithm, the key, and the wire format are identical on both paths -- only the implementation doing the work differs -- so the exception widens no algorithm choice and data written on one path stays readable on the other. An explicit `$preferSodium` argument outranks the variable.

- **Protocol-mandated OpenSSL interoperability.** The following classes use OpenSSL or `hash_hmac` because external protocols or standards require specific algorithms that libsodium does not implement. These are the only approved exceptions beyond TOTP and password hashing:
  - `SmimeEncryptor` -- X.509/PKCS#7 encryption for S/MIME email (RFC 8551), requires OpenSSL certificate operations
  - `SendgridWebhookVerifier`, `SesWebhookVerifier`, `MailgunWebhookVerifier` -- verify webhook signatures using the algorithm dictated by each provider (ECDSA, SNS signature verification, HMAC-SHA-256 respectively)
  - `HmacWebhookVerifier` -- generic HMAC-SHA-256 webhook signature verification for third-party integrations that mandate RFC 2104 HMAC
  - `S3Signer` -- AWS Signature Version 4 (HMAC-SHA-256 based) for S3-compatible storage APIs
  - WebAuthn extension -- FIDO2/WebAuthn attestation and assertion verification requires OpenSSL for COSE key parsing and ECDSA/RSA signature verification (FIDO Alliance specifications)
  - OAuth2 extension -- JWT RS256/ES256 token verification requires OpenSSL for RSA and ECDSA public key operations (RFC 7518)

## Consequences

### Positive

- **Zero external crypto dependencies.** No packages to audit, no supply chain risk for cryptographic primitives.
- **Safe defaults only.** Libsodium's API makes it hard to misuse: nonces are generated automatically, authentication is mandatory, and weak algorithms are not available.
- **Single key to manage.** One environment variable, one rotation procedure. Subkeys are derived deterministically - rotating the master key rotates everything.
- **Domain separation.** Even if one subkey is compromised (e.g., via a cache side channel), other subsystems remain protected. Different KDF contexts produce cryptographically independent keys.

### Negative

- **No algorithm flexibility.** The libsodium-only default means protocol-mandated interoperability (e.g., OpenSSL for JWT/WebAuthn, HMAC-SHA-256 for webhook verification) requires narrowly scoped exceptions documented above. General-purpose algorithm selection is not supported. FIPS-approved AES-256-GCM is available natively via libsodium; the OpenSSL implementation of that same cipher is reachable only through the narrowly scoped fallback described above.
- **Master key is a single point of failure.** If the master key is compromised, all derived keys are compromised. Mitigation: the key lives only in the environment, never in source code or config files.
- **Libsodium availability.** While bundled since PHP 7.2, some minimal PHP installations may not have `ext-sodium` enabled. Pulsar requires it.

### Neutral

- **Key rotation** requires re-encrypting data encrypted with the old master key. For audit chains, multi-key verification (checking entries against both old and new keys) can be used during the transition window. This is an operational procedure, not a framework limitation.
- **Operational guidance.** `PULSAR_MASTER_KEY` should be stored in a secret manager (Vault, AWS Secrets Manager, etc.) with a documented break-glass procedure for emergency access. The key must never appear in source code, config files, or CI logs. The `key:generate` command produces a cryptographically random key for initial setup.
