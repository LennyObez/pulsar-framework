<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Ceremony;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * VECTORS-WA-01: W3C WebAuthn conformance vector suite (ADR-0032).
 *
 * The 1.0.0 GA tag is gated on this suite running green in CI. Vectors are
 * imported from:
 *   - <https://github.com/web-auth/webauthn-test-vectors> (FIDO Alliance
 *     contributor corpus)
 *   - W3C WebAuthn Level 2 + Level 3 test cases published with the spec
 *   - WebAuthn algorithm-specific corner cases (alg:none refusal, RP-ID
 *     hash, counter monotonicity, AAGUID match, origin check)
 *
 * Coverage targets:
 *   - Registration ceremony × 7 attestation formats (none, packed full,
 *     packed self, fido-u2f, android-key, android-safetynet, apple, tpm).
 *   - Authentication ceremony with counter monotonicity.
 *   - Resident credential / passkey ceremony.
 *   - Cross-origin / RP confusion defense.
 *
 * Scaffold stage: the test reports a deliberate "not yet populated" message
 * and is marked as `incomplete` so CI surfaces it but does not block.
 * Population is a multi-PR follow-up tracked in the rc.x → 1.0.0 GA roadmap.
 */
#[CoversNothing]
final class W3cConformanceVectorTest extends TestCase
{
    #[Test]
    public function w3cConformanceVectorsRunGreen(): void
    {
        self::markTestIncomplete(
            'VECTORS-WA-01 conformance suite (ADR-0032) is not yet populated. '
            . 'Import W3C WebAuthn vectors and FIDO test corpus before tagging 1.0.0 GA. '
            . 'Tracking: docs/adr/0032-homegrown-auth-with-conformance-vectors-gate.md',
        );
    }
}
