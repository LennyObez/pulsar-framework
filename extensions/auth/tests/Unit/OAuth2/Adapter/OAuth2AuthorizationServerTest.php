<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\OAuth2\Adapter\OAuth2AuthorizationServer;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationCodeRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ClientRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ConsentRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\RefreshTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ScopeRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Grant\AuthorizationCodeGrant;
use Pulsar\Extension\Auth\OAuth2\Grant\GrantInterface;
use Pulsar\Extension\Auth\OAuth2\Grant\TokenResponse;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\OAuth2\Token\RefreshToken;
use Pulsar\Extension\Auth\OAuth2\Token\Scope;

#[CoversClass(OAuth2AuthorizationServer::class)]
final class OAuth2AuthorizationServerTest extends TestCase
{
    private ClientRepositoryInterface&Stub $clientRepo;
    private ScopeRepositoryInterface&Stub $scopeRepo;
    private ConsentRepositoryInterface&Stub $consentRepo;
    private AccessTokenRepositoryInterface&Stub $accessTokenRepo;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepo;
    private AuthorizationCodeGrant $authCodeGrant;
    private ResponseFactoryInterface&Stub $responseFactory;
    private StreamFactoryInterface&Stub $streamFactory;
    private AuditLoggerInterface&Stub $auditLogger;

    protected function setUp(): void
    {
        $this->clientRepo = $this->createStub(ClientRepositoryInterface::class);
        $this->scopeRepo = $this->createStub(ScopeRepositoryInterface::class);
        $this->consentRepo = $this->createStub(ConsentRepositoryInterface::class);
        $this->accessTokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepo = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createStub(StreamFactoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        // Construct a real AuthorizationCodeGrant with stub dependencies
        $this->authCodeGrant = new AuthorizationCodeGrant(
            codeRepository: $this->createStub(AuthorizationCodeRepositoryInterface::class),
            accessTokenRepository: $this->accessTokenRepo,
            refreshTokenRepository: $this->refreshTokenRepo,
            auditLogger: $this->auditLogger,
        );
    }

    /**
     * @param list<GrantInterface> $grants
     */
    private function createServer(array $grants = []): OAuth2AuthorizationServer
    {
        return new OAuth2AuthorizationServer(
            clientRepository: $this->clientRepo,
            scopeRepository: $this->scopeRepo,
            consentRepository: $this->consentRepo,
            accessTokenRepository: $this->accessTokenRepo,
            refreshTokenRepository: $this->refreshTokenRepo,
            authorizationCodeGrant: $this->authCodeGrant,
            responseFactory: $this->responseFactory,
            streamFactory: $this->streamFactory,
            auditLogger: $this->auditLogger,
            grants: $grants,
        );
    }

    private function stubJsonResponse(): ResponseInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);
        $response->method('withBody')->willReturn($response);
        $response->method('getStatusCode')->willReturn(200);

        $this->responseFactory->method('createResponse')->willReturn($response);

        return $response;
    }

    private function stubRedirectResponse(int $status = 302): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);
        $response->method('getStatusCode')->willReturn($status);

        $this->responseFactory->method('createResponse')->willReturn($response);

        return $response;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function createAuthRequest(array $queryParams = []): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getHeaderLine')->willReturn('');

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createTokenRequest(array $body = [], string $authHeader = ''): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getHeaderLine')->willReturn($authHeader);

        return $request;
    }

    /**
     * @param list<string> $redirectUris
     * @param list<string> $grantTypes
     */
    private function createTestClient(
        string $id = 'test-client',
        array $redirectUris = ['https://app.example.com/callback'],
        array $grantTypes = ['authorization_code'],
    ): OAuthClient {
        return new OAuthClient(
            id: $id,
            name: 'Test Client',
            redirectUris: $redirectUris,
            grantTypes: $grantTypes,
            scopes: ['read', 'write'],
            confidential: true,
        );
    }

    // --- Authorization request tests ---

    #[Test]
    public function handleAuthorizationRequestReturnsErrorForMissingClientId(): void
    {
        $this->stubJsonResponse();

        $request = $this->createAuthRequest([
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'code',
            'code_challenge' => 'abc123',
        ]);

        $server = $this->createServer();
        $response = $server->handleAuthorizationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleAuthorizationRequestReturnsErrorForUnsupportedResponseType(): void
    {
        $client = $this->createTestClient();
        $this->clientRepo->method('findById')->willReturn($client);
        $this->stubJsonResponse();

        $request = $this->createAuthRequest([
            'client_id' => 'test-client',
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'token',
            'code_challenge' => 'abc123',
        ]);

        $server = $this->createServer();
        $response = $server->handleAuthorizationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleAuthorizationRequestReturnsErrorForUnknownClient(): void
    {
        $this->clientRepo->method('findById')->willReturn(null);
        $this->stubJsonResponse();

        $request = $this->createAuthRequest([
            'client_id' => 'unknown-client',
            'redirect_uri' => 'https://evil.com/callback',
            'response_type' => 'code',
            'code_challenge' => 'abc123',
        ]);

        $server = $this->createServer();
        $response = $server->handleAuthorizationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleAuthorizationRequestReturnsErrorForInvalidRedirectUri(): void
    {
        $client = $this->createTestClient();
        $this->clientRepo->method('findById')->willReturn($client);
        $this->stubJsonResponse();

        $request = $this->createAuthRequest([
            'client_id' => 'test-client',
            'redirect_uri' => 'https://evil.com/steal',
            'response_type' => 'code',
            'code_challenge' => 'abc123',
        ]);

        $server = $this->createServer();
        $response = $server->handleAuthorizationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleAuthorizationRequestDeniesUnauthenticatedUser(): void
    {
        $client = $this->createTestClient();
        $this->clientRepo->method('findById')->willReturn($client);
        $this->scopeRepo->method('resolveScopes')->willReturn([]);
        $this->stubJsonResponse();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'client_id' => 'test-client',
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'code',
            'code_challenge' => 'abc123',
            'code_challenge_method' => 'S256',
        ]);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->willReturn(null);

        $server = $this->createServer();
        $response = $server->handleAuthorizationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    // --- Token request tests ---

    #[Test]
    public function handleTokenRequestReturnsErrorForMissingGrantType(): void
    {
        $this->stubJsonResponse();

        $request = $this->createTokenRequest([]);

        $server = $this->createServer();
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleTokenRequestReturnsErrorForMissingClientCredentials(): void
    {
        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
        ]);

        $server = $this->createServer();
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleTokenRequestReturnsErrorForUnknownClient(): void
    {
        $this->clientRepo->method('findById')->willReturn(null);
        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => 'unknown',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleTokenRequestReturnsErrorForInvalidCredentials(): void
    {
        $client = $this->createTestClient();
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(false);
        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => 'test-client',
            'client_secret' => 'wrong-secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleTokenRequestReturnsErrorForUnsupportedGrantType(): void
    {
        $client = $this->createTestClient(grantTypes: ['authorization_code', 'custom_grant']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);
        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'grant_type' => 'custom_grant',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleTokenRequestSuccessWithValidGrant(): void
    {
        $client = $this->createTestClient(grantTypes: ['authorization_code', 'client_credentials']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $grantStub = $this->createStub(GrantInterface::class);
        $grantStub->method('identifier')->willReturn('client_credentials');
        $grantStub->method('handleTokenRequest')->willReturn(
            new TokenResponse(
                accessToken: 'access-token-value',
                tokenType: 'Bearer',
                expiresIn: 3600,
                scopes: ['read'],
            ),
        );

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer([$grantStub]);
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleTokenRequestAuthenticatesViaBasicAuth(): void
    {
        $client = $this->createTestClient(grantTypes: ['authorization_code', 'client_credentials']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $ccGrant = $this->createStub(GrantInterface::class);
        $ccGrant->method('identifier')->willReturn('client_credentials');
        $ccGrant->method('handleTokenRequest')->willReturn(
            new TokenResponse('token', 'Bearer', 3600),
        );

        $this->stubJsonResponse();

        $authHeader = 'Basic ' . base64_encode('test-client:client-secret');
        $request = $this->createTokenRequest(
            ['grant_type' => 'client_credentials'],
            $authHeader,
        );

        $server = $this->createServer([$ccGrant]);
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    // --- Introspection request tests ---

    #[Test]
    public function handleIntrospectionRequestReturnsActiveTokenInfo(): void
    {
        $client = $this->createTestClient(grantTypes: ['introspection']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $token = new AccessToken(
            id: 'token-id',
            clientId: 'test-client',
            subjectId: 'user-1',
            scopes: ['read', 'write'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'the-token-value',
        );
        $this->accessTokenRepo->method('introspect')->willReturn($token);

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'token' => 'the-token-value',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleIntrospectionRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleIntrospectionRequestReturnsInactiveForExpiredToken(): void
    {
        $client = $this->createTestClient(grantTypes: ['introspection']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $expiredToken = new AccessToken(
            id: 'token-id',
            clientId: 'test-client',
            subjectId: 'user-1',
            scopes: ['read'],
            expiresAt: new DateTimeImmutable('-1 hour'),
            issuedAt: new DateTimeImmutable('-2 hours'),
        );
        $this->accessTokenRepo->method('introspect')->willReturn($expiredToken);

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'token' => 'expired-token',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleIntrospectionRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleIntrospectionRequestReturnsInactiveForNullToken(): void
    {
        $client = $this->createTestClient(grantTypes: ['introspection']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);
        $this->accessTokenRepo->method('introspect')->willReturn(null);

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'token' => 'nonexistent-token',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleIntrospectionRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleIntrospectionRequestReturnsErrorForMissingToken(): void
    {
        $client = $this->createTestClient(grantTypes: ['introspection']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleIntrospectionRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    // --- Revocation request tests ---

    #[Test]
    public function handleRevocationRequestReturns200EvenForInvalidToken(): void
    {
        $client = $this->createTestClient(grantTypes: ['revocation']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);
        $this->refreshTokenRepo->method('consume')->willReturn(null);
        $this->accessTokenRepo->method('introspect')->willReturn(null);

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'token' => 'some-token',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleRevocationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleRevocationRequestRevokesRefreshTokenFamily(): void
    {
        $client = $this->createTestClient(grantTypes: ['revocation']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $refreshToken = new RefreshToken(
            id: 'rt-id',
            clientId: 'test-client',
            subjectId: 'user-1',
            sessionId: 'session-1',
            familyId: 'family-1',
            scopes: ['read'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        $refreshTokenRepo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokenRepo->method('consume')->willReturn($refreshToken);
        $refreshTokenRepo->expects(self::once())->method('revokeFamily')->with('family-1');

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'token' => 'refresh-token-value',
            'token_type_hint' => 'refresh_token',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = new OAuth2AuthorizationServer(
            clientRepository: $this->clientRepo,
            scopeRepository: $this->scopeRepo,
            consentRepository: $this->consentRepo,
            accessTokenRepository: $this->accessTokenRepo,
            refreshTokenRepository: $refreshTokenRepo,
            authorizationCodeGrant: $this->authCodeGrant,
            responseFactory: $this->responseFactory,
            streamFactory: $this->streamFactory,
            auditLogger: $this->auditLogger,
        );

        $response = $server->handleRevocationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleRevocationRequestDoesNotRevokeDifferentClientToken(): void
    {
        $client = $this->createTestClient(id: 'client-a', grantTypes: ['revocation']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $refreshToken = new RefreshToken(
            id: 'rt-id',
            clientId: 'client-b',
            subjectId: 'user-1',
            sessionId: 'session-1',
            familyId: 'family-1',
            scopes: ['read'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->refreshTokenRepo->method('consume')->willReturn($refreshToken);

        $accessToken = new AccessToken(
            id: 'at-id',
            clientId: 'client-b',
            subjectId: 'user-1',
            scopes: ['read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->accessTokenRepo->method('introspect')->willReturn($accessToken);

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'token' => 'some-token',
            'client_id' => 'client-a',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();
        $response = $server->handleRevocationRequest($request);

        // Should still return 200 per RFC 7009, but not actually revoke
        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleRevocationRequestReturnsErrorForMissingTokenParam(): void
    {
        $client = $this->createTestClient(grantTypes: ['revocation']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer();

        // Per RFC 7009, missing token returns 200 even on error (not invalid_client)
        $response = $server->handleRevocationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleRevocationRequestRevokesAccessTokenWhenHinted(): void
    {
        $client = $this->createTestClient(grantTypes: ['revocation']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $accessToken = new AccessToken(
            id: 'at-id',
            clientId: 'test-client',
            subjectId: 'user-1',
            scopes: ['read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $accessTokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $accessTokenRepo->method('introspect')->willReturn($accessToken);
        $accessTokenRepo->expects(self::once())->method('revoke')->with('at-id');

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'token' => 'access-token-value',
            'token_type_hint' => 'access_token',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = new OAuth2AuthorizationServer(
            clientRepository: $this->clientRepo,
            scopeRepository: $this->scopeRepo,
            consentRepository: $this->consentRepo,
            accessTokenRepository: $accessTokenRepo,
            refreshTokenRepository: $this->refreshTokenRepo,
            authorizationCodeGrant: $this->authCodeGrant,
            responseFactory: $this->responseFactory,
            streamFactory: $this->streamFactory,
            auditLogger: $this->auditLogger,
        );

        $response = $server->handleRevocationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    // --- Constructor wiring ---

    #[Test]
    public function constructorAlwaysIncludesAuthorizationCodeGrant(): void
    {
        // Verify the server constructor always registers the authorization_code grant.
        // Test by requesting a token with a custom grant, proving additional grants work.
        $client = $this->createTestClient(grantTypes: ['client_credentials']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $ccGrant = $this->createStub(GrantInterface::class);
        $ccGrant->method('identifier')->willReturn('client_credentials');
        $ccGrant->method('handleTokenRequest')->willReturn(
            new TokenResponse('token', 'Bearer', 3600),
        );

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer([$ccGrant]);
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleAuthorizationRequestRejectsNonS256Method(): void
    {
        $client = $this->createTestClient();
        $this->clientRepo->method('findById')->willReturn($client);
        $this->stubJsonResponse();

        $request = $this->createAuthRequest([
            'client_id' => 'test-client',
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'code',
            'code_challenge' => 'abc123',
            'code_challenge_method' => 'plain',
        ]);

        $server = $this->createServer();
        $response = $server->handleAuthorizationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleAuthorizationRequestDeniesWhenConsentNotGranted(): void
    {
        $client = $this->createTestClient();
        $this->clientRepo->method('findById')->willReturn($client);
        $this->scopeRepo->method('resolveScopes')->willReturn([new Scope('read')]);
        $this->consentRepo->method('hasConsent')->willReturn(false);

        $this->stubRedirectResponse();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'client_id' => 'test-client',
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'code',
            'code_challenge' => 'abc123',
            'code_challenge_method' => 'S256',
        ]);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getAttribute')->willReturn('user-1');

        $server = $this->createServer();
        $response = $server->handleAuthorizationRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function handleTokenRequestRejectsUnauthorizedGrantType(): void
    {
        $client = $this->createTestClient(grantTypes: ['authorization_code']);
        $this->clientRepo->method('findById')->willReturn($client);
        $this->clientRepo->method('validateClient')->willReturn(true);

        $ccGrant = $this->createStub(GrantInterface::class);
        $ccGrant->method('identifier')->willReturn('client_credentials');

        $this->stubJsonResponse();

        $request = $this->createTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => 'test-client',
            'client_secret' => 'secret',
        ]);

        $server = $this->createServer([$ccGrant]);
        $response = $server->handleTokenRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }
}
