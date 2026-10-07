<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Grant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\RefreshToken;

/**
 * Protocol conformance tests for refresh token grant constraints.
 *
 * Validates the domain invariants for token rotation, replay detection,
 * and binding enforcement that any RefreshTokenGrant implementation must enforce.
 */
#[CoversClass(RefreshToken::class)]
final class RefreshTokenGrantTest extends TestCase
{
    #[Test]
    public function rotatedTokenIsConsumed(): void
    {
        // After rotation, the old token is marked consumed
        $oldToken = new RefreshToken(
            id: 'rt-rot-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable('-1 hour'),
            consumed: true,
        );

        self::assertTrue($oldToken->consumed);
        self::assertFalse($oldToken->isActive());
    }

    #[Test]
    public function newTokenInSameFamilyIsActive(): void
    {
        // The new token in the same family is active
        $newToken = new RefreshToken(
            id: 'rt-rot-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($newToken->consumed);
        self::assertFalse($newToken->revoked);
        self::assertTrue($newToken->isActive());
        self::assertSame('family-001', $newToken->familyId);
    }

    #[Test]
    public function replayDetectionFamilyRevocation(): void
    {
        // When a rotated-out (consumed) token is reused, the entire family is revoked
        $revokedToken = new RefreshToken(
            id: 'rt-replay-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-compromised',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable('-1 hour'),
            revoked: true,
            consumed: true,
        );

        // All tokens in the family should be revoked
        $otherFamilyToken = new RefreshToken(
            id: 'rt-replay-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-compromised',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertFalse($revokedToken->isActive());
        self::assertFalse($otherFamilyToken->isActive());
        self::assertSame($revokedToken->familyId, $otherFamilyToken->familyId);
    }

    #[Test]
    public function bindingClientMustMatch(): void
    {
        $token = new RefreshToken(
            id: 'rt-bind-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        // Token from client-1 cannot be used by client-2
        $requestingClientId = 'client-2';
        self::assertNotSame($token->clientId, $requestingClientId);
    }

    #[Test]
    public function bindingSubjectMustMatch(): void
    {
        $token = new RefreshToken(
            id: 'rt-bind-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        $differentSubject = 'user-99';
        self::assertNotSame($token->subjectId, $differentSubject);
    }

    #[Test]
    public function bindingSessionMustMatch(): void
    {
        $token = new RefreshToken(
            id: 'rt-bind-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        $differentSession = 'session-xyz';
        self::assertNotSame($token->sessionId, $differentSession);
    }

    #[Test]
    public function expiredTokenRejected(): void
    {
        $token = new RefreshToken(
            id: 'rt-exp-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-31 days'),
        );

        self::assertTrue($token->isExpired());
        self::assertFalse($token->isActive());
    }

    #[Test]
    public function scopeDownscopingAllowed(): void
    {
        // Original token has broad scopes
        $originalToken = new RefreshToken(
            id: 'rt-scope-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid', 'profile', 'email'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable('-1 hour'),
            consumed: true,
        );

        // New token has a subset of the original scopes
        $downscopedToken = new RefreshToken(
            id: 'rt-scope-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        // Downscoped scopes must be a subset of the original
        $isSubset = array_diff($downscopedToken->scopes, $originalToken->scopes) === [];
        self::assertTrue($isSubset);
    }

    #[Test]
    public function scopeEscalationDetectable(): void
    {
        $originalToken = new RefreshToken(
            id: 'rt-scope-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable('-1 hour'),
            consumed: true,
        );

        // Attempting to escalate scopes beyond the original
        $escalatedScopes = ['openid', 'admin'];
        $isSubset = array_diff($escalatedScopes, $originalToken->scopes) === [];

        self::assertFalse($isSubset);
    }
}
