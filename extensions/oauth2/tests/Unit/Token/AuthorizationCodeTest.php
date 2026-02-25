<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\AuthorizationCode;

final class AuthorizationCodeTest extends TestCase
{
    #[Test]
    public function construction_sets_all_properties(): void
    {
        $now = new DateTimeImmutable();
        $expires = new DateTimeImmutable('+10 minutes');

        $code = new AuthorizationCode(
            id: 'code-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            redirectUri: 'https://example.com/cb',
            scopes: ['openid', 'profile'],
            codeChallenge: 'challenge-hash',
            codeChallengeMethod: 'S256',
            expiresAt: $expires,
            issuedAt: $now,
        );

        self::assertSame('code-1', $code->id);
        self::assertSame('client-1', $code->clientId);
        self::assertSame('user-1', $code->subjectId);
        self::assertSame('https://example.com/cb', $code->redirectUri);
        self::assertSame(['openid', 'profile'], $code->scopes);
        self::assertSame('challenge-hash', $code->codeChallenge);
        self::assertSame('S256', $code->codeChallengeMethod);
        self::assertFalse($code->revoked);
        self::assertNull($code->codeValue);
        self::assertNull($code->nonce);
    }

    #[Test]
    public function is_expired_returns_true_for_past_expiry(): void
    {
        $code = new AuthorizationCode(
            id: 'code-1',
            clientId: 'c',
            subjectId: 's',
            redirectUri: 'https://example.com/cb',
            scopes: [],
            codeChallenge: 'ch',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('-1 minute'),
            issuedAt: new DateTimeImmutable('-11 minutes'),
        );

        self::assertTrue($code->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_for_future_expiry(): void
    {
        $code = new AuthorizationCode(
            id: 'code-1',
            clientId: 'c',
            subjectId: 's',
            redirectUri: 'https://example.com/cb',
            scopes: [],
            codeChallenge: 'ch',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($code->isExpired());
    }

    #[Test]
    public function debug_info_redacts_sensitive_fields(): void
    {
        $code = new AuthorizationCode(
            id: 'code-1',
            clientId: 'c',
            subjectId: 's',
            redirectUri: 'https://example.com/cb',
            scopes: [],
            codeChallenge: 'sensitive-challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            codeValue: 'secret-code-value',
            nonce: 'secret-nonce',
        );

        $debug = $code->__debugInfo();

        self::assertSame('[REDACTED]', $debug['codeValue']);
        self::assertSame('[REDACTED]', $debug['codeChallenge']);
        self::assertSame('[REDACTED]', $debug['nonce']);
        self::assertSame('code-1', $debug['id']);
    }

    #[Test]
    public function nonce_is_optional(): void
    {
        $code = new AuthorizationCode(
            id: 'code-1',
            clientId: 'c',
            subjectId: 's',
            redirectUri: 'https://example.com/cb',
            scopes: [],
            codeChallenge: 'ch',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            nonce: 'oidc-nonce',
        );

        self::assertSame('oidc-nonce', $code->nonce);
    }
}
