<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\AccessToken;

#[CoversClass(AccessToken::class)]
final class AccessTokenTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $expiresAt = new DateTimeImmutable('+1 hour');
        $issuedAt = new DateTimeImmutable();

        $token = new AccessToken(
            id: 'at-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid', 'profile'],
            expiresAt: $expiresAt,
            issuedAt: $issuedAt,
            revoked: false,
            tokenValue: 'secret-value',
        );

        self::assertSame('at-001', $token->id);
        self::assertSame('client-1', $token->clientId);
        self::assertSame('user-42', $token->subjectId);
        self::assertSame(['openid', 'profile'], $token->scopes);
        self::assertSame($expiresAt, $token->expiresAt);
        self::assertSame($issuedAt, $token->issuedAt);
        self::assertFalse($token->revoked);
        self::assertSame('secret-value', $token->tokenValue);
    }

    #[Test]
    public function defaultsAreApplied(): void
    {
        $token = new AccessToken(
            id: 'at-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($token->revoked);
        self::assertNull($token->tokenValue);
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureExpiry(): void
    {
        $token = new AccessToken(
            id: 'at-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($token->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueForPastExpiry(): void
    {
        $token = new AccessToken(
            id: 'at-004',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-1 hour'),
        );

        self::assertTrue($token->isExpired());
    }

    #[Test]
    public function isActiveWhenNotRevokedAndNotExpired(): void
    {
        $token = new AccessToken(
            id: 'at-005',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            revoked: false,
        );

        self::assertTrue($token->isActive());
    }

    #[Test]
    public function isNotActiveWhenRevoked(): void
    {
        $token = new AccessToken(
            id: 'at-006',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function isNotActiveWhenExpired(): void
    {
        $token = new AccessToken(
            id: 'at-007',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-1 hour'),
            revoked: false,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function isNotActiveWhenBothRevokedAndExpired(): void
    {
        $token = new AccessToken(
            id: 'at-008',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-1 hour'),
            revoked: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function debugInfoRedactsTokenValue(): void
    {
        $token = new AccessToken(
            id: 'at-009',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'super-secret-token-value',
        );

        $debug = $token->__debugInfo();

        self::assertSame('[REDACTED]', $debug['tokenValue']);
        self::assertSame('at-009', $debug['id']);
        self::assertSame('client-1', $debug['clientId']);
        self::assertSame('user-42', $debug['subjectId']);
    }

    #[Test]
    public function debugInfoShowsNullTokenValueAsNull(): void
    {
        $token = new AccessToken(
            id: 'at-010',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: null,
        );

        $debug = $token->__debugInfo();

        self::assertNull($debug['tokenValue']);
    }
}
