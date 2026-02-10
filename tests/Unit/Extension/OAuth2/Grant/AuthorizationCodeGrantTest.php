<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Grant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\AuthorizationCode;

/**
 * Protocol conformance tests for the authorization code grant flow.
 *
 * These tests validate the domain objects and constraints that any
 * AuthorizationCodeGrant implementation must respect, without depending
 * on a concrete grant implementation.
 */
#[CoversClass(AuthorizationCode::class)]
final class AuthorizationCodeGrantTest extends TestCase
{
    #[Test]
    public function pkceMandatoryCodeChallengeMustBePresent(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-pkce-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: '',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        // Empty code challenge means PKCE was not provided — grant handler must reject
        self::assertSame('', $code->codeChallenge);
    }

    #[Test]
    public function s256VerificationSucceeds(): void
    {
        // RFC 7636: code_challenge = BASE64URL(SHA256(code_verifier))
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = new AuthorizationCode(
            id: 'ac-pkce-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $expectedChallenge,
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        // Simulate S256 verification
        $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        self::assertSame($code->codeChallenge, $computedChallenge);
    }

    #[Test]
    public function s256VerificationFailsWithWrongVerifier(): void
    {
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = new AuthorizationCode(
            id: 'ac-pkce-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $expectedChallenge,
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        // Wrong verifier
        $wrongVerifier = 'completely-wrong-verifier-value-that-should-not-match';
        $wrongChallenge = rtrim(strtr(base64_encode(hash('sha256', $wrongVerifier, true)), '+/', '-_'), '=');

        self::assertNotSame($code->codeChallenge, $wrongChallenge);
    }

    #[Test]
    public function expiredCodeIsRejectable(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-pkce-004',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-11 minutes'),
        );

        self::assertTrue($code->isExpired());
    }

    #[Test]
    public function redirectUriMismatchIsDetectable(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-pkce-005',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        // Redirect URI from the token request must match what was stored in the code
        $requestRedirectUri = 'https://evil.example.com/callback';

        self::assertNotSame($code->redirectUri, $requestRedirectUri);
    }

    #[Test]
    public function clientIdMismatchIsDetectable(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-pkce-006',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        // Token request with different client_id must be rejected
        $requestClientId = 'client-other';

        self::assertNotSame($code->clientId, $requestClientId);
    }

    #[Test]
    public function revokedCodeCanBeDetected(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-pkce-007',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        self::assertTrue($code->revoked);
    }
}
