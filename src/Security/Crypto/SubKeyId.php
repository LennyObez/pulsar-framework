<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;

/**
 * Centralised subkey assignments for the framework's KDF.
 *
 * ADR-0006: `sodium_crypto_kdf_derive_from_key()` mixes BOTH the sub-key id and
 * the 8-byte context into the derived bytes, so the unit of domain separation is
 * the `(id, context)` PAIR — not the id on its own:
 *
 * - Two callers passing the same `(id, context)` pair derive the same key bytes.
 *   That collapses the cross-subsystem isolation the architecture relies on and
 *   is the collision this registry exists to prevent.
 * - Two callers sharing an id under different contexts derive cryptographically
 *   independent keys. ADR-0006's assignment table does exactly that on purpose:
 *   id 2 keys both `audit___` and `mailaudt`, id 3 keys `session_`, `pseudo__`,
 *   `rcvrycod` and `stud_enc`.
 *
 * A case below therefore records who allocated an id, not who owns it
 * exclusively. Most ids listed here are also live under other contexts, and each
 * case says which — magic-number literals spread across modules would leave that
 * surface invisible to review, and this enum is the single registry callers
 * import instead.
 *
 * NEVER repurpose an existing case for a different subsystem; always allocate a
 * fresh case at the bottom of the list, with a context no other subsystem uses.
 * Reuse silently swaps key material between modules and breaks the ADR-0006
 * isolation contract.
 *
 * The registry is enforced, not advisory:
 * `tests/Unit/Security/Crypto/SubKeyIdRegistryTest.php` walks every KDF call
 * site under `src/` and `extensions/`, resolves each literal `(id, context)`
 * pair, and fails the build when a pair is derived by a file that is not its
 * declared owner. Adding a case here means adding its pair there.
 *
 * A subsystem storing data classified `Restricted`
 * ({@see \Pulsar\Security\Compliance\DataClassification}) needs a pair of its
 * own rather than the default encryption key: see "Data classification scheme"
 * in `docs/security/asvs-l2-matrix.md`.
 * @api
 */
#[Api(since: '1.0.0')]
enum SubKeyId: int
{
    /** Symmetric encryption, context `encrypt_` ({@see Encryptor} default). */
    case Encryption = 1;

    /**
     * Audit chain HMAC, context `audit___` (AuditEntry chain root).
     *
     * Id 2 also keys the mail audit HMAC under `mailaudt`
     * ({@see \Pulsar\Mail\Audit\MailAuditor}).
     */
    case AuditChain = 2;

    /**
     * Pseudonymisation service, context `pseudo__`
     * ({@see \Pulsar\Security\Compliance\Pseudonymization\PseudonymizationService}).
     *
     * Id 3 also keys session AEAD encryption under `session_`, two-factor
     * recovery codes under `rcvrycod`, and the Studio encryptor under
     * `stud_enc`.
     */
    case Pseudonymization = 3;

    /**
     * Allocated for CSRF token signing.
     *
     * No CSRF component derives under it today — {@see \Pulsar\Security\Csrf\CsrfTokenManager}
     * and its encrypted variant take an {@see EncryptorInterface} rather than the
     * master key. The case stays allocated so the id is not handed to a second
     * claimant, but id 4 is already live under three other contexts: `stud_mac`
     * (Studio archive MAC), `sess_fp_` (session fingerprint validator) and
     * `totpscrt` (TOTP secret encryption). A CSRF key added later must pick a
     * context none of those uses.
     */
    case Csrf = 4;

    /**
     * ORM encrypted-column key, default context `orm__enc`
     * ({@see \Pulsar\Extension\Orm\Config\EncryptionConfig}).
     *
     * Id 5 also keys the Studio chain-link MAC under `stud_chn`.
     */
    case Orm = 5;

    /**
     * Allocated for social SSO state-token signing (`extensions/auth/src/Social`).
     *
     * Nothing derives under it today; the social flow signs its state token with
     * the general-purpose encryptor. Id 6 is live under `integ_sg`
     * ({@see \Pulsar\Integrity\ManifestSigner}).
     */
    case SocialSso = 6;

    /**
     * Allocated for Studio session signing (`extensions/studio`).
     *
     * Studio's live derivations are elsewhere: `stud_enc` (id 3), `stud_mac`
     * (id 4), `stud_chn` (id 5) and `stu_seed` (id 15). Id 7 is live under
     * `fw_cache` ({@see \Pulsar\Cache\FrameworkCache}) and `tokenize`
     * ({@see TokenizationService}).
     */
    case Studio = 7;

    /**
     * Allocated for OAuth2 access-token signing (`extensions/auth/src/OAuth2`).
     *
     * OAuth2 ships inside the auth extension rather than in a package of its
     * own, and its only master-key derivation is the authorization-code hash at
     * id 19, context `oa2_code`. Id 8 is live under `bld_sign`
     * ({@see \Pulsar\Build\ArtifactIntegrityVerifier}) and `app_cenc`
     * (application cache value encryption).
     */
    case OAuth2AccessToken = 8;

    /**
     * Allocated for OAuth2 refresh-token signing (`extensions/auth/src/OAuth2`).
     *
     * As with id 8, nothing in the auth extension derives under it. Id 9 is live
     * under `app_cobs`, the application cache's AAD binding MAC
     * ({@see \Pulsar\Cache\Application\Encryption\EncryptedCacheDecorator}).
     */
    case OAuth2RefreshToken = 9;

    /**
     * Allocated for WebAuthn challenge signing (`extensions/auth/src/WebAuthn`).
     *
     * WebAuthn likewise ships inside the auth extension, and its flow derives
     * nothing from the master key. Id 10 is live under `que_aead`
     * ({@see \Pulsar\Queue\Middleware\AeadPayloadEncryptor}) and `cms_prev`
     * (CMS preview token signing).
     */
    case WebAuthnChallenge = 10;

    /**
     * 11 has no case here, and the gap is permanent.
     *
     * The idempotency envelope below was already sealing data under 12 when this
     * registry was written, so the registry had to accept the id in use rather than
     * renumber envelopes sitting in live stores. That left 11 with no case.
     *
     * It is not a free id: the CMS extension signs media URLs under
     * `(11, 'cms_mdia')`. A future subsystem may take 11 only with a context
     * that one does not use.
     *
     * Never move a live subsystem onto another pair. Changing a subsystem's id or
     * context re-keys that subsystem, and everything it has already sealed becomes
     * unreadable.
     */

    /**
     * Idempotency cache HMAC envelope, context `idemcach`.
     *
     * Pinned to 12 because `SignedIdempotencyEnvelope` has used that value since
     * 1.0.0 — the registry follows the id already in the code rather than the other
     * way round, since moving it would re-key every sealed envelope already sitting
     * in a live store. This is the assignment that leaves 11 without a case above.
     *
     * Id 12 also keys the CMS export archive under `cms_xprt`.
     */
    case IdempotencyEnvelope = 12;

    /**
     * New first-party allocations take the next free id in 13–63. Ids below 13
     * predate this registry and are shared between subsystems under distinct
     * contexts, as each case above records.
     */

    /**
     * ORM blind-index keyed hashing. Distinct from Orm=5 so the
     * searchable-index key is never the encryption key, and derived from the
     * master key rather than a public constant.
     *
     * Its context is computed at runtime — the blind-index label hashed down to
     * 8 bytes — so the pair cannot be checked statically. Id 13 also keys the
     * CMS download token under `cms_dwnl`.
     */
    case OrmBlindIndex = 13;

    /**
     * PSD2 SCA dynamic-linking authentication code, context `psd2_sca`. Keys the
     * HMAC that binds the code to the transaction, so the code cannot be
     * recomputed offline from the public transaction details.
     *
     * Id 14 also keys the CMS order-export evidence hash under `cms_evid`.
     */
    case Psd2ScaDynamicLinking = 14;

    /**
     * Compliance verification's key-derivation probe, contexts `cmplprb1` and
     * `cmplprb2`.
     *
     * Owns an id of its own for the same reason every other subsystem does, even
     * though it seals nothing: {@see \Pulsar\Compliance\Verification\RuntimeVerifier}
     * runs in production, where {@see Test} must not be used, and borrowing a live
     * subsystem's pair would derive that subsystem's real key material into the
     * verifier. The bytes derived under these contexts are compared with each
     * other, zeroed, and never stored — the derivation itself is the evidence.
     *
     * Id 15 also keys the secret vault (`secrets_`), the CMS API-key pepper
     * (`cms_apik`) and the Studio evidence chain seed (`stu_seed`).
     */
    case ComplianceDerivationProbe = 15;

    /**
     * Compliance evidence chain HMAC, context `cmp_evid`.
     *
     * Keys the signature {@see \Pulsar\Compliance\Verification\EvidenceChain} puts on
     * each evidence record and the linkage to its predecessor. Its own id because
     * the chain is what makes the evidence trail tamper-evident: sharing the audit
     * chain's pair (2, `audit___`) would let a record from either trail validate in
     * the other, which is exactly the cross-subsystem substitution ADR-0006
     * separates.
     *
     * 16 to 19 have no case. Each was already in use as a literal when this case
     * was allocated — 16 and 17 by anti-spam signing, 18 by compliance log
     * pseudonymisation, 19 by the auth extension's authorization-code hashing —
     * and the registry follows the ids in the code rather than renumbering live
     * subsystems, for the reason stated at the top of this file.
     *
     * Id 20 also keys analytics visitor hashing under `anal_vis`.
     */
    case ComplianceEvidenceChain = 20;

    /**
     * Application cache key hashing for observability, context `app_kobs`.
     *
     * {@see \Pulsar\Cache\Application\CacheManager} hands this key to the cache
     * event emitter, which HMACs the raw cache key with it so the key reaches log
     * lines and metric labels as a digest instead of plaintext. That digest is
     * PUBLISHED, which is why it needs a pair of its own: the emitter used to
     * derive `(9, 'app_cobs')` — the pair that authenticates the AAD binding of
     * every encrypted cache entry — and a published MAC under the key that
     * authenticates stored data is a signing oracle for that authentication.
     */
    case CacheKeyObservability = 21;

    /**
     * Sealed backup archive AEAD, context `bkup_arc`.
     *
     * Keys the `crypto_secretstream_xchacha20poly1305` stream that
     * {@see \Pulsar\Resilience\Backup\ArchiveSeal} seals every backup archive
     * with. Its own pair, and none of the reasons below is optional:
     *
     *  - An archive holds a copy of EVERY estate the deployment protects —
     *    database rows, the audit chain, uploaded files — in one file that leaves
     *    the host. Deriving it from a pair a live subsystem already uses would
     *    hand the archive key to whoever holds that subsystem's key, and hand that
     *    subsystem's key to whoever holds an archive.
     *  - The archive key is the one derived key that is used OFF the running host,
     *    by a restore process on another machine during a recovery. A pair shared
     *    with an online subsystem would put that subsystem's key material into a
     *    disaster-recovery runbook.
     *
     * 22 is the next free id after {@see CacheKeyObservability}, and `bkup_arc`
     * is used under no other id. Re-keying it would make every archive already
     * written unreadable, which is the failure mode a backup exists to prevent, so
     * this pair is pinned harder than most.
     */
    case BackupArchiveSeal = 22;

    /**
     * AI inference content digests, context `ai_cdgst`.
     *
     * Keys the BLAKE2b digests {@see \Pulsar\AI\Audit\AuditingAiClient} writes in
     * place of the prompt, the completion, the system prompt and the JSON schema
     * of every model call. Its own pair, for two reasons that pull in opposite
     * directions and both land here:
     *
     *  - The digests are PUBLISHED into the audit file, next to the entry HMAC.
     *    Deriving them under the audit chain's own pair (2, `audit___`) would put
     *    a chosen-message MAC oracle — the prompt is attacker-influenced — under
     *    the key that makes the chain tamper-evident. That is the same defect
     *    {@see CacheKeyObservability} was allocated to undo, in a place where the
     *    consequence is a forgeable audit entry.
     *  - The digests must nonetheless be KEYED. A prompt is low-entropy far more
     *    often than not — a customer name, an account number, one of a few hundred
     *    templates — so an unkeyed hash of it is recovered by guessing, and the
     *    audit file would hold a reversible copy of the personal data the egress
     *    seam refused to send.
     *
     * 23 is the next free id after {@see BackupArchiveSeal}, and `ai_cdgst` is
     * used under no other id. Re-keying it does not lose data, but it breaks the
     * one property the digests exist for: two entries carrying the same prompt
     * stop digesting alike, so an investigation can no longer correlate across the
     * rotation.
     */
    case AiInferenceDigest = 23;

    /** Reserved range for third-party extensions: 64–127. */

    /** Reserved for testing only — never use in production. */
    case Test = 255;
}
