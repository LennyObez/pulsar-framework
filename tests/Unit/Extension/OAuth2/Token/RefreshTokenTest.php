<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\RefreshToken;

#[CoversClass(RefreshToken::class)]
final class RefreshTokenTest extends TestCase
{
    #[Test]
    public function constructionPreservesBindingFields(): void
    {
        $expiresAt = new DateTimeImmutable('+30 days');
        $issuedAt = new DateTimeImmutable();

        $token = new RefreshToken(
            id: 'rt-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: ['openid', 'email'],
            expiresAt: $expiresAt,
            issuedAt: $issuedAt,
            revoked: false,
            consumed: false,
            tokenValue: 'refresh-secret',
        );

        self::assertSame('rt-001', $token->id);
        self::assertSame('client-1', $token->clientId);
        self::assertSame('user-42', $token->subjectId);
        self::assertSame('session-abc', $token->sessionId);
        self::assertSame('family-xyz', $token->familyId);
        self::assertSame(['openid', 'email'], $token->scopes);
        self::assertSame($expiresAt, $token->expiresAt);
        self::assertSame($issuedAt, $token->issuedAt);
        self::assertFalse($token->revoked);
        self::assertFalse($token->consumed);
        self::assertSame('refresh-secret', $token->tokenValue);
    }

    #[Test]
    public function defaultsAreApplied(): void
    {
        $token = new RefreshToken(
            id: 'rt-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($token->revoked);
        self::assertFalse($token->consumed);
        self::assertNull($token->tokenValue);
    }

    #[Test]
    public function isActiveWhenNotRevokedNotConsumedNotExpired(): void
    {
        $token = new RefreshToken(
            id: 'rt-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertTrue($token->isActive());
    }

    #[Test]
    public function isNotActiveWhenRevoked(): void
    {
        $token = new RefreshToken(
            id: 'rt-004',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function isNotActiveWhenConsumed(): void
    {
        $token = new RefreshToken(
            id: 'rt-005',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            consumed: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function isNotActiveWhenExpired(): void
    {
        $token = new RefreshToken(
            id: 'rt-006',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-31 days'),
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function isNotActiveWhenRevokedAndConsumedAndExpired(): void
    {
        $token = new RefreshToken(
            id: 'rt-007',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-31 days'),
            revoked: true,
            consumed: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureExpiry(): void
    {
        $token = new RefreshToken(
            id: 'rt-008',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($token->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueForPastExpiry(): void
    {
        $token = new RefreshToken(
            id: 'rt-009',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-31 days'),
        );

        self::assertTrue($token->isExpired());
    }

    #[Test]
    public function debugInfoRedactsTokenValue(): void
    {
        $token = new RefreshToken(
            id: 'rt-010',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'refresh-secret-value',
        );

        $debug = $token->__debugInfo();

        self::assertSame('[REDACTED]', $debug['tokenValue']);
        self::assertSame('rt-010', $debug['id']);
        self::assertSame('client-1', $debug['clientId']);
        self::assertSame('user-42', $debug['subjectId']);
        self::assertSame('session-abc', $debug['sessionId']);
        self::assertSame('family-xyz', $debug['familyId']);
    }

    #[Test]
    public function debugInfoShowsNullTokenValueAsNull(): void
    {
        $token = new RefreshToken(
            id: 'rt-011',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-xyz',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: null,
        );

        $debug = $token->__debugInfo();

        self::assertNull($debug['tokenValue']);
    }
}
