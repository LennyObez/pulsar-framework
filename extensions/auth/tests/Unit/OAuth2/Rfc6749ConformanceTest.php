<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * VECTORS-OAUTH-01: OAuth2 / OIDC conformance vector suite (ADR-0032).
 *
 * The 1.0.0 GA tag is gated on this suite running green in CI. Vectors are
 * imported from:
 *   - OpenID Foundation Self-Certification test suite
 *     <https://openid.net/certification/>
 *   - RFC 6749 §A — authorization grant examples
 *   - RFC 7636 §A — PKCE S256 example
 *   - RFC 7662 — token introspection examples
 *   - RFC 8176 — AMR/ACR claim mapping examples
 *   - RFC 9068 — JWT access token profile
 *   - OAuth in the Wild attack corpus (mix-up, redirect-URI splitting,
 *     state/nonce omission, scope upgrade without consent)
 *
 * Coverage targets:
 *   - Authorization code grant with PKCE S256.
 *   - Refresh token rotation + replay detection + family invalidation.
 *   - Redirect URI strict exact match + fragment refusal + scheme allowlist.
 *   - State / nonce binding.
 *   - AMR/ACR claim mapping to TwoFactorStatus.
 *   - Mix-up attack defense (RFC 8252).
 *   - Token introspection (RFC 7662 active flag, scope/aud/exp).
 *   - Token revocation (RFC 7009).
 *
 * Scaffold stage: see ADR-0032.
 */
#[CoversNothing]
final class Rfc6749ConformanceTest extends TestCase
{
    #[Test]
    public function rfc6749ConformanceVectorsRunGreen(): void
    {
        self::markTestIncomplete(
            'VECTORS-OAUTH-01 conformance suite (ADR-0032) is not yet populated. '
            . 'Import OpenID Foundation Self-Certification corpus + RFC examples + '
            . 'OAuth-in-the-Wild attack corpus before tagging 1.0.0 GA. '
            . 'Tracking: docs/adr/0032-homegrown-auth-with-conformance-vectors-gate.md',
        );
    }
}
