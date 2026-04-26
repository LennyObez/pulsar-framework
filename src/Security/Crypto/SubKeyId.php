<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;

/**
 * Centralised subkey-id assignments for the framework's KDF.
 *
 * F33.6 / F29.14 / ADR-0006: each subsystem that calls
 * `MasterKey::deriveSubKey($id, $context)` must use a unique
 * `$id` integer. The KDF's domain-separation guarantee depends on
 * this: two callers passing the same `(id, context)` pair derive
 * the same key bytes, which collapses the cross-subsystem
 * isolation the architecture relies on. Magic-number literals
 * scattered across modules made the collision surface invisible
 * to review — this enum is the single registry callers should
 * import.
 *
 * NEVER repurpose an existing case for a different subsystem;
 * always allocate a fresh case at the bottom of the list. Reuse
 * silently swaps key material between modules and breaks the
 * ADR-0006 isolation contract.
 *
 * Cases mirror the historical assignments visible in commits
 * 9d4a0eb4 / b2ab9264 / e2be3b3a.
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

    /** Idempotency cache HMAC envelope (F21.3). */
    case IdempotencyEnvelope = 11;

    /** Reserved range for first-party extensions: 12–63. */

    /** Reserved range for third-party extensions: 64–127. */

    /** Reserved for testing only — never use in production. */
    case Test = 255;
}
