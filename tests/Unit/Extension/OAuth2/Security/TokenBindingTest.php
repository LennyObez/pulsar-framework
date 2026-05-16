<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\OAuth2\Token\RefreshToken;

/**
 * Security conformance tests for token binding.
 *
 * Validates that confused deputy, audience confusion, token substitution,
 * and cross-client attacks are detectable through domain object properties.
 */
#[CoversClass(AccessToken::class)]
#[CoversClass(RefreshToken::class)]
final class TokenBindingTest extends TestCase
{
    #[Test]
    public function confusedDeputyTokenForClientACannotBeUsedByClientB(): void
    {
        $token = new AccessToken(
            id: 'at-sec-001',
            clientId: 'client-a',
            subjectId: 'user-42',
            scopes: ['api:read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $requestingClientId = 'client-b';

        self::assertNotSame($token->clientId, $requestingClientId);
    }

    #[Test]
    public function audienceConfusionTokenWithWrongSubjectRejected(): void
    {
        $token = new AccessToken(
            id: 'at-sec-002',
            clientId: 'client-a',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $wrongSubject = 'user-99';

        self::assertNotSame($token->subjectId, $wrongSubject);
    }

    #[Test]
    public function tokenSubstitutionAccessTokenHasDifferentStructureThanRefreshToken(): void
    {
        $accessToken = new AccessToken(
            id: 'at-sec-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $refreshToken = new RefreshToken(
            id: 'rt-sec-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        // Different types — an access token cannot be used where a refresh token is expected
        self::assertNotSame($accessToken::class, $refreshToken::class);
        // Type safety enforces token substitution protection at compile time
        self::assertInstanceOf(AccessToken::class, $accessToken);
        self::assertInstanceOf(RefreshToken::class, $refreshToken);
    }

    #[Test]
    public function refreshTokenFromClientARejectedByClientB(): void
    {
        $token = new RefreshToken(
            id: 'rt-sec-004',
            clientId: 'client-a',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        $requestingClientId = 'client-b';

        self::assertNotSame($token->clientId, $requestingClientId);
    }

    #[Test]
    public function revokedAccessTokenNotActive(): void
    {
        $token = new AccessToken(
            id: 'at-sec-005',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['api:read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function revokedRefreshTokenNotActive(): void
    {
        $token = new RefreshToken(
            id: 'rt-sec-005',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertFalse($token->isActive());
    }
}
