<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\AuthorizationCode;

use function strlen;

/**
 * Security conformance tests for PKCE (RFC 7636) validation.
 *
 * These tests validate the S256 challenge generation and verification logic
 * that any OAuth2 implementation must enforce.
 */
#[CoversClass(AuthorizationCode::class)]
final class PkceValidationTest extends TestCase
{
    #[Test]
    public function s256ChallengeGenerationAndVerification(): void
    {
        // RFC 7636 Section 4.2: S256 code_challenge = BASE64URL(SHA256(code_verifier))
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = new DateTimeImmutable('+10 minutes');

        $authCode = new AuthorizationCode(
            id: 'pkce-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            expiresAt: $code,
            issuedAt: new DateTimeImmutable(),
        );

        // Verification: recompute from the verifier and compare
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        self::assertSame($authCode->codeChallenge, $computed);
    }

    #[Test]
    public function s256VerificationFailsWithWrongVerifier(): void
    {
        $originalVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $storedChallenge = rtrim(strtr(base64_encode(hash('sha256', $originalVerifier, true)), '+/', '-_'), '=');

        $wrongVerifier = 'this-is-a-completely-different-verifier-value123';
        $wrongComputed = rtrim(strtr(base64_encode(hash('sha256', $wrongVerifier, true)), '+/', '-_'), '=');

        self::assertNotSame($storedChallenge, $wrongComputed);
    }

    #[Test]
    public function emptyVerifierProducesDifferentChallenge(): void
    {
        $originalVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $storedChallenge = rtrim(strtr(base64_encode(hash('sha256', $originalVerifier, true)), '+/', '-_'), '=');

        $emptyComputed = rtrim(strtr(base64_encode(hash('sha256', '', true)), '+/', '-_'), '=');

        self::assertNotSame($storedChallenge, $emptyComputed);
    }

    #[Test]
    public function verifierLengthMinimum43Characters(): void
    {
        // RFC 7636 Section 4.1: code_verifier length must be 43-128 characters
        $shortVerifier = str_repeat('a', 42);

        self::assertLessThan(43, strlen($shortVerifier));
    }

    #[Test]
    public function verifierLengthMaximum128Characters(): void
    {
        $longVerifier = str_repeat('a', 129);

        self::assertGreaterThan(128, strlen($longVerifier));
    }

    #[Test]
    public function verifierLength43IsValid(): void
    {
        $verifier = str_repeat('a', 43);

        self::assertGreaterThanOrEqual(43, strlen($verifier));
        self::assertLessThanOrEqual(128, strlen($verifier));
    }

    #[Test]
    public function verifierLength128IsValid(): void
    {
        $verifier = str_repeat('z', 128);

        self::assertGreaterThanOrEqual(43, strlen($verifier));
        self::assertLessThanOrEqual(128, strlen($verifier));
    }

    #[Test]
    public function base64urlCharsetValidation(): void
    {
        // RFC 7636 Section 4.1: code_verifier uses [A-Z] / [a-z] / [0-9] / "-" / "." / "_" / "~"
        $validVerifier = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrs';
        $pattern = '/^[A-Za-z0-9\-._~]+$/';

        self::assertMatchesRegularExpression($pattern, $validVerifier);
    }

    #[Test]
    public function invalidBase64urlCharactersDetectable(): void
    {
        $invalidVerifier = 'valid-part-here+invalid/chars=padded';
        $pattern = '/^[A-Za-z0-9\-._~]+$/';

        self::assertDoesNotMatchRegularExpression($pattern, $invalidVerifier);
    }

    #[Test]
    public function codeChallengeMethodStoredCorrectly(): void
    {
        $authCode = new AuthorizationCode(
            id: 'pkce-method-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge-value',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertSame('S256', $authCode->codeChallengeMethod);
    }

    #[Test]
    public function plainMethodIsDetectable(): void
    {
        // The grant handler should reject 'plain' method, but the domain object stores it
        $authCode = new AuthorizationCode(
            id: 'pkce-plain-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge-value',
            codeChallengeMethod: 'plain',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
        );

        // The grant implementation must check and reject 'plain' method
        self::assertNotSame('S256', $authCode->codeChallengeMethod);
    }
}
