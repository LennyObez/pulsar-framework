<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\AuthorizationCode;

#[CoversClass(AuthorizationCode::class)]
final class AuthorizationCodeTest extends TestCase
{
    #[Test]
    public function constructionPreservesPkceBinding(): void
    {
        $expiresAt = new DateTimeImmutable('+10 minutes');
        $issuedAt = new DateTimeImmutable();

        $code = new AuthorizationCode(
            id: 'ac-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid', 'profile'],
            codeChallenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            codeChallengeMethod: 'S256',
            expiresAt: $expiresAt,
            issuedAt: $issuedAt,
            codeValue: 'auth-code-secret',
            nonce: 'random-nonce',
        );

        self::assertSame('ac-001', $code->id);
        self::assertSame('client-1', $code->clientId);
        self::assertSame('user-42', $code->subjectId);
        self::assertSame('https://app.example.com/callback', $code->redirectUri);
        self::assertSame(['openid', 'profile'], $code->scopes);
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $code->codeChallenge);
        self::assertSame('S256', $code->codeChallengeMethod);
        self::assertSame($expiresAt, $code->expiresAt);
        self::assertSame($issuedAt, $code->issuedAt);
        self::assertFalse($code->revoked);
        self::assertSame('auth-code-secret', $code->codeValue);
        self::assertSame('random-nonce', $code->nonce);
    }

    #[Test]
    public function defaultsAreApplied(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: [],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($code->revoked);
        self::assertNull($code->codeValue);
        self::assertNull($code->nonce);
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureExpiry(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: [],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertFalse($code->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueForPastExpiry(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-004',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: [],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-11 minutes'),
        );

        self::assertTrue($code->isExpired());
    }

    #[Test]
    public function debugInfoRedactsSensitiveFields(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-005',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            codeValue: 'secret-code',
            nonce: 'secret-nonce',
        );

        $debug = $code->__debugInfo();

        self::assertSame('[REDACTED]', $debug['codeValue']);
        self::assertSame('[REDACTED]', $debug['codeChallenge']);
        self::assertSame('[REDACTED]', $debug['nonce']);
        self::assertSame('ac-005', $debug['id']);
        self::assertSame('S256', $debug['codeChallengeMethod']);
    }

    #[Test]
    public function debugInfoShowsNullCodeValueAsNull(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-006',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: [],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        $debug = $code->__debugInfo();

        self::assertNull($debug['codeValue']);
        self::assertNull($debug['nonce']);
    }
}
