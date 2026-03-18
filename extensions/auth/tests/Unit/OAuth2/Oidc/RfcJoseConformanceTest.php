<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * VECTORS-JOSE-01: JOSE / JWT conformance vector suite (ADR-0032).
 *
 * The 1.0.0 GA tag is gated on this suite running green in CI. Vectors are
 * imported from:
 *   - RFC 7515 §A — JWS examples (HS256, RS256, ES256)
 *   - RFC 7517 §A — JWK / JWKS examples
 *   - RFC 7518 §A — algorithm-specific examples
 *   - RFC 7519 — JWT claims (iss, aud, exp, nbf, iat, jti)
 *   - JWT attack corpus (the canonical 2015–2024 catalogue):
 *       * `alg:none` accepted as valid
 *       * RS256 token verified with HS256 key (key confusion)
 *       * `kid` path traversal
 *       * embedded JWK reuse
 *       * critical header bypass
 *       * empty signature acceptance
 *
 * Coverage targets:
 *   - Signing / verification round-trip across HS256/RS256/ES256/EdDSA.
 *   - JWKS publication with kid rotation.
 *   - alg whitelist (RS256 only for OIDC public).
 *   - alg:none rejection.
 *   - Key-confusion defense (algorithm-key mismatch rejection).
 *   - Standard claim validation: exp / nbf / iat / iss / aud.
 *
 * Scaffold stage: see ADR-0032.
 */
#[CoversNothing]
final class RfcJoseConformanceTest extends TestCase
{
    #[Test]
    public function rfcJoseConformanceVectorsRunGreen(): void
    {
        self::markTestIncomplete(
            'VECTORS-JOSE-01 conformance suite (ADR-0032) is not yet populated. '
            . 'Import RFC 7515-7519 examples + JWT attack corpus (alg:none, RS256→HS256, '
            . 'kid traversal, critical header bypass) before tagging 1.0.0 GA. '
            . 'Tracking: docs/adr/0032-homegrown-auth-with-conformance-vectors-gate.md',
        );
    }
}
