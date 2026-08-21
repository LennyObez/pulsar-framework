<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;

/**
 * Centralised subkey-id assignments for the framework's KDF.
 *
 * ADR-0006: each subsystem that calls
 * `MasterKey::deriveSubKey($id, $context)` must use a unique
 * `$id` integer. The KDF's domain-separation guarantee depends
 * on it: two callers passing the same `(id, context)` pair derive
 * the same key bytes, which collapses the cross-subsystem
 * isolation the architecture relies on. Magic-number literals
 * spread across modules would leave that collision surface
 * invisible to review — this enum is the single registry callers
 * import.
 *
 * NEVER repurpose an existing case for a different subsystem;
 * always allocate a fresh case at the bottom of the list. Reuse
 * silently swaps key material between modules and breaks the
 * ADR-0006 isolation contract.
 *
 * A subsystem storing data classified `Restricted`
 * ({@see \Pulsar\Security\Compliance\DataClassification}) needs a case
 * of its own rather than the default encryption key: see "Data
 * classification scheme" in `docs/security/asvs-l2-matrix.md`.
 * @api
 */
#[Api(since: '1.0.0')]
enum SubKeyId: int
{
    /** Symmetric encryption (Encryptor default). */
    case Encryption = 1;

    /** Audit chain HMAC (AuditEntry chain root). */
    case AuditChain = 2;

    /** Pseudonymisation service (PseudonymizationService). */
    case Pseudonymization = 3;

    /** CSRF token signing. */
    case Csrf = 4;

    /** ORM encrypted-column key. */
    case Orm = 5;

    /** Social SSO state-token signing. */
    case SocialSso = 6;

    /** Studio session signing (extensions/studio). */
    case Studio = 7;

    /** OAuth2 access-token signing (extensions/oauth2). */
    case OAuth2AccessToken = 8;

    /** OAuth2 refresh-token signing (extensions/oauth2). */
    case OAuth2RefreshToken = 9;

    /** WebAuthn challenge signing (extensions/webauthn). */
    case WebAuthnChallenge = 10;

    /**
     * 11 is intentionally unallocated, and the gap is permanent.
     *
     * The idempotency envelope below was already sealing data under 12 when this
     * registry was written, so the registry had to accept the id in use rather than
     * renumber envelopes sitting in live stores. That left 11 with no owner.
     *
     * Never move a live subsystem onto it. Changing a subsystem's id re-keys that
     * subsystem, and everything it has already sealed becomes unreadable. A future
     * subsystem may claim 11 freely.
     */

    /**
     * Idempotency cache HMAC envelope.
     *
     * Pinned to 12 because `SignedIdempotencyEnvelope` has used that value since
     * 1.0.0 — the registry follows the id already in the code rather than the other
     * way round, since moving it would re-key every sealed envelope already sitting
     * in a live store. This is the assignment that leaves 11 empty above.
     */
    case IdempotencyEnvelope = 12;

    /** Reserved range for first-party extensions: 13–63. */

    /**
     * ORM blind-index keyed hashing. Distinct from Orm=5 so the
     * searchable-index key is never the encryption key, and derived from the
     * master key rather than a public constant.
     */
    case OrmBlindIndex = 13;

    /**
     * PSD2 SCA dynamic-linking authentication code. Keys the HMAC that
     * binds the code to the transaction, so the code cannot be recomputed
     * offline from the public transaction details.
     */
    case Psd2ScaDynamicLinking = 14;

    /**
     * Compliance verification's key-derivation probe.
     *
     * Owns an id of its own for the same reason every other subsystem does, even
     * though it seals nothing: {@see \Pulsar\Compliance\Verification\RuntimeVerifier}
     * runs in production, where {@see Test} must not be used, and borrowing a live
     * subsystem's id would derive that subsystem's real key material into the
     * verifier. The bytes derived under this id are compared with each other,
     * zeroed, and never stored — the derivation itself is the evidence.
     */
    case ComplianceDerivationProbe = 15;

    /**
     * Compliance evidence chain HMAC.
     *
     * Keys the signature {@see \Pulsar\Compliance\Verification\EvidenceChain} puts on
     * each evidence record and the linkage to its predecessor. Its own id because
     * the chain is what makes the evidence trail tamper-evident: sharing the audit
     * chain's key (2) would let a record from either trail validate in the other,
     * which is exactly the cross-subsystem substitution ADR-0006 separates.
     *
     * 16 to 19 are deliberately skipped. Each was already in use as a literal when
     * this case was allocated — 16 and 17 by anti-spam signing, 18 by compliance
     * log pseudonymisation, 19 by the auth extension's authorization-code hashing —
     * and the registry follows the ids in the code rather than renumbering live
     * subsystems, for the reason stated at the top of this file.
     */
    case ComplianceEvidenceChain = 20;

    /** Reserved range for third-party extensions: 64–127. */

    /** Reserved for testing only — never use in production. */
    case Test = 255;
}
