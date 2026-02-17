<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Grant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\OAuth2\Client\OAuthClient;
use Pulsar\Extension\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\OAuth2\Grant\RefreshTokenGrant;
use Pulsar\Extension\OAuth2\Token\InMemoryRefreshTokenRepository;
use Pulsar\Extension\OAuth2\Token\RefreshToken;
use Pulsar\Http\Message\ServerRequest;

final class RefreshTokenGrantTest extends TestCase
{
    private RefreshTokenGrant $grant;
    private InMemoryRefreshTokenRepository $refreshRepo;
    private AccessTokenRepositoryInterface&Stub $accessRepo;
    private AuditLoggerInterface&Stub $auditLogger;

    private OAuthClient $client;

    protected function setUp(): void
    {
        $this->refreshRepo = new InMemoryRefreshTokenRepository();
        $this->accessRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->grant = new RefreshTokenGrant(
            $this->refreshRepo,
            $this->accessRepo,
            $this->auditLogger,
            new OAuth2Config(accessTokenTtl: 3600, refreshTokenTtl: 86400 * 30),
        );

        $this->client = new OAuthClient(
            id: 'client-1',
            name: 'Test Client',
            redirectUris: ['https://app.test/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'profile', 'email'],
            confidential: true,
        );
    }

    #[Test]
    public function identifierReturnsRefreshToken(): void
    {
        self::assertSame('refresh_token', $this->grant->identifier());
    }

    #[Test]
    public function handleTokenRequestThrowsWhenRefreshTokenMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: refresh_token');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsWhenRefreshTokenEmpty(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => ''],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: refresh_token');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsWhenRefreshTokenInvalid(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => 'non-existent-token'],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('invalid, expired, or already used');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestThrowsWhenClientDoesNotMatch(): void
    {
        $tokenValue = 'valid-refresh-token-value';
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'other-client',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshRepo->persist($token);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => $tokenValue],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('not issued to this client');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestIssuesNewTokenPair(): void
    {
        $tokenValue = 'my-refresh-token';
        $token = new RefreshToken(
            id: 'rt-1',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'sess-1',
            familyId: 'fam-1',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshRepo->persist($token);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => $tokenValue],
        );

        $response = $this->grant->handleTokenRequest($request, $this->client);

        self::assertSame('Bearer', $response->tokenType);
        self::assertSame(3600, $response->expiresIn);
        self::assertSame(['openid', 'profile'], $response->scopes);
        self::assertNotEmpty($response->accessToken);
        self::assertNotNull($response->refreshToken);
        self::assertNotSame($tokenValue, $response->refreshToken);
    }

    #[Test]
    public function handleTokenRequestAllowsScopeNarrowing(): void
    {
        $tokenValue = 'rt-scope-narrow';
        $token = new RefreshToken(
            id: 'rt-2',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-2',
            scopes: ['openid', 'profile', 'email'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshRepo->persist($token);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'refresh_token' => $tokenValue,
                'scope' => 'openid email',
            ],
        );

        $response = $this->grant->handleTokenRequest($request, $this->client);

        self::assertSame(['openid', 'email'], $response->scopes);
    }

    #[Test]
    public function handleTokenRequestRejectsScopeExpansion(): void
    {
        $tokenValue = 'rt-scope-expand';
        $token = new RefreshToken(
            id: 'rt-3',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-3',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshRepo->persist($token);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [
                'refresh_token' => $tokenValue,
                'scope' => 'openid admin',
            ],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage("Scope 'admin' was not included in the original grant");

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestDetectsReplay(): void
    {
        $tokenValue = 'rt-replay';
        $token = new RefreshToken(
            id: 'rt-4',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-4',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshRepo->persist($token);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => $tokenValue],
        );

        // First use should succeed
        $this->grant->handleTokenRequest($request, $this->client);

        // Second use should fail (replay detection)
        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('invalid, expired, or already used');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestLogsAuditOnReplay(): void
    {
        $tokenValue = 'rt-replay-audit';
        $token = new RefreshToken(
            id: 'rt-5',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-5',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshRepo->persist($token);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        // First call is the successful refresh, second is the replay detection
        $auditLogger->expects(self::exactly(2))->method('log');

        $grant = new RefreshTokenGrant($this->refreshRepo, $this->accessRepo, $auditLogger);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => $tokenValue],
        );

        $grant->handleTokenRequest($request, $this->client);

        try {
            $grant->handleTokenRequest($request, $this->client);
        } catch (OAuth2Exception) {
            // Expected
        }
    }

    #[Test]
    public function handleTokenRequestWithNonStringRefreshToken(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => ['array']],
        );

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: refresh_token');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestWithNullParsedBody(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Missing required parameter: refresh_token');

        $this->grant->handleTokenRequest($request, $this->client);
    }

    #[Test]
    public function handleTokenRequestDefaultsToOriginalScopes(): void
    {
        $tokenValue = 'rt-default-scopes';
        $token = new RefreshToken(
            id: 'rt-6',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-6',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $this->refreshRepo->persist($token);

        // No scope parameter: should use original scopes
        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => $tokenValue],
        );

        $response = $this->grant->handleTokenRequest($request, $this->client);

        self::assertSame(['openid', 'profile'], $response->scopes);
    }

    #[Test]
    public function handleTokenRequestUsesConfigAccessTokenTtl(): void
    {
        $config = new OAuth2Config(accessTokenTtl: 600, refreshTokenTtl: 86400);
        $refreshRepo = new InMemoryRefreshTokenRepository();
        $grant = new RefreshTokenGrant(
            $refreshRepo,
            $this->accessRepo,
            $this->auditLogger,
            $config,
        );

        $tokenValue = 'rt-custom-ttl';
        $token = new RefreshToken(
            id: 'rt-7',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-7',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
        $refreshRepo->persist($token);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['refresh_token' => $tokenValue],
        );

        $response = $grant->handleTokenRequest($request, $this->client);

        self::assertSame(600, $response->expiresIn);
    }
}
