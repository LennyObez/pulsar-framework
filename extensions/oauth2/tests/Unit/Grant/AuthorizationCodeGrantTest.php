<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Grant;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\OAuth2\Client\OAuthClient;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\RefreshTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\OAuth2\Grant\AuthorizationCodeGrant;
use Pulsar\Extension\OAuth2\Token\InMemoryAuthorizationCodeRepository;
use Pulsar\Http\Message\ServerRequest;

use function base64_encode;
use function hash;
use function rtrim;
use function strtr;

final class AuthorizationCodeGrantTest extends TestCase
{
    private AuthorizationCodeGrant $grant;
    private InMemoryAuthorizationCodeRepository $codeRepo;
    private AccessTokenRepositoryInterface&Stub $accessRepo;
    private RefreshTokenRepositoryInterface&Stub $refreshRepo;
    private AuditLoggerInterface&Stub $auditLogger;

    private OAuthClient $client;

    protected function setUp(): void
    {
        $this->codeRepo = new InMemoryAuthorizationCodeRepository();
        $this->accessRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshRepo = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->grant = new AuthorizationCodeGrant(
            $this->codeRepo,
            $this->accessRepo,
            $this->refreshRepo,
            $this->auditLogger,
        );

        $this->client = new OAuthClient(
            id: 'client-1',
            name: 'Test App',
            redirectUris: ['https://app.test/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid', 'profile'],
            confidential: true,
        );
    }

    #[Test]
    public function identifierReturnsAuthorizationCode(): void
    {
        self::assertSame('authorization_code', $this->grant->identifier());
    }

    #[Test]
    public function createAuthorizationCodeRejectsNonS256Method(): void
    {
        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Only S256');

        $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-1',
            redirectUri: 'https://app.test/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'plain',
        );
    }

    #[Test]
    public function createAuthorizationCodeRejectsInvalidRedirectUri(): void
    {
        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Invalid redirect_uri');

        $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-1',
            redirectUri: 'https://evil.test/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
        );
    }

    #[Test]
    public function createAuthorizationCodeReturnsCode(): void
    {
        $code = $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-42',
            redirectUri: 'https://app.test/callback',
            scopes: ['openid', 'profile'],
            codeChallenge: 'test-challenge',
            codeChallengeMethod: 'S256',
            nonce: 'test-nonce',
        );

        self::assertSame('client-1', $code->clientId);
        self::assertSame('user-42', $code->subjectId);
        self::assertSame('https://app.test/callback', $code->redirectUri);
        self::assertSame(['openid', 'profile'], $code->scopes);
        self::assertSame('test-challenge', $code->codeChallenge);
        self::assertSame('S256', $code->codeChallengeMethod);
        self::assertSame('test-nonce', $code->nonce);
        self::assertNotNull($code->codeValue);
    }

    #[Test]
    public function handleTokenRequestThrowsWhenCodeMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'redirect_uri' => 'https://app.test/callback',
                'code_verifier' => 'verifier',
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: code');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsWhenRedirectUriMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => 'some-code',
                'code_verifier' => 'verifier',
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: redirect_uri');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsWhenCodeVerifierMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => 'some-code',
                'redirect_uri' => 'https://app.test/callback',
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: code_verifier');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsForInvalidCode(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => 'invalid-code',
                'redirect_uri' => 'https://app.test/callback',
                'code_verifier' => 'verifier',
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('invalid, expired, or already used');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsForClientMismatch(): void
    {
        $codeVerifier = 'test-code-verifier-string-at-least-43-chars-long';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-1',
            redirectUri: 'https://app.test/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        $otherClient = new OAuthClient(
            id: 'other-client',
            name: 'Other',
            redirectUris: ['https://app.test/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => $code->codeValue,
                'redirect_uri' => 'https://app.test/callback',
                'code_verifier' => $codeVerifier,
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('not issued to this client');

        $this->grant->handleTokenRequest($request, $otherClient);
    }

    #[Test]
    public function handleTokenRequestThrowsForRedirectUriMismatch(): void
    {
        $codeVerifier = 'test-code-verifier-string-at-least-43-chars-long';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-1',
            redirectUri: 'https://app.test/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => $code->codeValue,
                'redirect_uri' => 'https://different.test/callback',
                'code_verifier' => $codeVerifier,
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('redirect_uri does not match');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsForInvalidPkce(): void
    {
        $codeVerifier = 'test-code-verifier-string-at-least-43-chars-long';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-1',
            redirectUri: 'https://app.test/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => $code->codeValue,
                'redirect_uri' => 'https://app.test/callback',
                'code_verifier' => 'wrong-verifier-that-does-not-match-challenge',
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('PKCE code_verifier is invalid');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestSucceedsWithValidPkce(): void
    {
        $codeVerifier = 'test-code-verifier-string-at-least-43-chars-long';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-42',
            redirectUri: 'https://app.test/callback',
            scopes: ['openid', 'profile'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => $code->codeValue,
                'redirect_uri' => 'https://app.test/callback',
                'code_verifier' => $codeVerifier,
            ],
        );

        $response = $this->grant->handleTokenRequest($request, $this->client);

        self::assertSame('Bearer', $response->tokenType);
        self::assertSame(3600, $response->expiresIn);
        self::assertSame(['openid', 'profile'], $response->scopes);
        self::assertNotEmpty($response->accessToken);
        self::assertNotNull($response->refreshToken);
    }

    #[Test]
    public function handleTokenRequestRejectsCodeReuse(): void
    {
        $codeVerifier = 'test-code-verifier-string-at-least-43-chars-long';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $this->grant->createAuthorizationCode(
            client: $this->client,
            subjectId: 'user-1',
            redirectUri: 'https://app.test/callback',
            scopes: ['openid'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'code' => $code->codeValue,
                'redirect_uri' => 'https://app.test/callback',
                'code_verifier' => $codeVerifier,
            ],
        );

        // First use succeeds
        $this->grant->handleTokenRequest($request, $this->client);

        // Second use fails
        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('invalid, expired, or already used');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestWithNullParsedBody(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: code');

        $this->grant->handleTokenRequest($request, $this->client);
    }
}
