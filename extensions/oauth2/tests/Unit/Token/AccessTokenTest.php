<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\AccessToken;

final class AccessTokenTest extends TestCase
{
    #[Test]
    public function construction_sets_all_properties(): void
    {
        $now = new DateTimeImmutable();
        $expires = new DateTimeImmutable('+1 hour');

        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['read', 'write'],
            expiresAt: $expires,
            issuedAt: $now,
        );

        self::assertSame('tok-1', $token->id);
        self::assertSame('client-1', $token->clientId);
        self::assertSame('user-1', $token->subjectId);
        self::assertSame(['read', 'write'], $token->scopes);
        self::assertSame($expires, $token->expiresAt);
        self::assertSame($now, $token->issuedAt);
        self::assertFalse($token->revoked);
        self::assertNull($token->tokenValue);
    }

    #[Test]
    public function is_expired_returns_true_for_past_expiry(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 hour'),
            issuedAt: new DateTimeImmutable('-2 hours'),
        );

        self::assertTrue($token->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_for_future_expiry(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($token->isExpired());
    }

    #[Test]
    public function is_active_returns_true_when_not_revoked_and_not_expired(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertTrue($token->isActive());
    }

    #[Test]
    public function is_active_returns_false_when_revoked(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function is_active_returns_false_when_expired(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 hour'),
            issuedAt: new DateTimeImmutable('-2 hours'),
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function debug_info_redacts_token_value(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'secret-value',
        );

        $debug = $token->__debugInfo();

        self::assertSame('[REDACTED]', $debug['tokenValue']);
        self::assertSame('tok-1', $debug['id']);
    }

    #[Test]
    public function debug_info_shows_null_for_absent_token_value(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $debug = $token->__debugInfo();

        self::assertNull($debug['tokenValue']);
    }
}
