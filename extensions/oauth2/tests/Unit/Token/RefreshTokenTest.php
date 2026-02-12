<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\RefreshToken;

final class RefreshTokenTest extends TestCase
{
    #[Test]
    public function construction_sets_all_properties(): void
    {
        $now = new DateTimeImmutable();
        $expires = new DateTimeImmutable('+30 days');

        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-1',
            scopes: ['read', 'write'],
            expiresAt: $expires,
            issuedAt: $now,
        );

        self::assertSame('rt-1', $token->id);
        self::assertSame('client-1', $token->clientId);
        self::assertSame('user-1', $token->subjectId);
        self::assertSame('sess-1', $token->sessionId);
        self::assertSame('fam-1', $token->familyId);
        self::assertSame(['read', 'write'], $token->scopes);
        self::assertFalse($token->revoked);
        self::assertFalse($token->consumed);
        self::assertNull($token->tokenValue);
    }

    #[Test]
    public function is_expired_returns_true_for_past_expiry(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 day'),
            issuedAt: new DateTimeImmutable('-2 days'),
        );

        self::assertTrue($token->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_for_future_expiry(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($token->isExpired());
    }

    #[Test]
    public function is_active_when_not_revoked_not_consumed_not_expired(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertTrue($token->isActive());
    }

    #[Test]
    public function is_active_returns_false_when_revoked(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function is_active_returns_false_when_consumed(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            consumed: true,
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function is_active_returns_false_when_expired(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 day'),
            issuedAt: new DateTimeImmutable('-2 days'),
        );

        self::assertFalse($token->isActive());
    }

    #[Test]
    public function debug_info_redacts_token_value(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'secret-refresh',
        );

        $debug = $token->__debugInfo();

        self::assertSame('[REDACTED]', $debug['tokenValue']);
        self::assertSame('rt-1', $debug['id']);
    }

    #[Test]
    public function debug_info_shows_null_for_absent_token_value(): void
    {
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertNull($token->__debugInfo()['tokenValue']);
    }

    #[Test]
    public function token_pair_construction(): void
    {
        $access = new \Pulsar\Extension\OAuth2\Token\AccessToken(
            id: 'at-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $refresh = new RefreshToken(
            id: 'rt-1',
            clientId: 'c',
            subjectId: 's',
            sessionId: 'sess',
            familyId: 'fam',
            scopes: [],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        $pair = new \Pulsar\Extension\OAuth2\Token\TokenPair($access, $refresh);

        self::assertSame($access, $pair->accessToken);
        self::assertSame($refresh, $pair->refreshToken);
    }
}
