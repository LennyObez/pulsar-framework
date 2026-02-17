<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Grant;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\OAuth2\Client\OAuthClient;
use Pulsar\Extension\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\ScopeRepositoryInterface;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\OAuth2\Grant\ClientCredentialsGrant;
use Pulsar\Extension\OAuth2\Token\Scope;
use Pulsar\Http\Message\ServerRequest;

final class ClientCredentialsGrantTest extends TestCase
{
    private ClientCredentialsGrant $grant;
    private AccessTokenRepositoryInterface&Stub $tokenRepo;
    private ScopeRepositoryInterface&Stub $scopeRepo;
    private AuditLoggerInterface&Stub $auditLogger;

    protected function setUp(): void
    {
        $this->tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->scopeRepo = $this->createStub(ScopeRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->grant = new ClientCredentialsGrant(
            $this->tokenRepo,
            $this->scopeRepo,
            $this->auditLogger,
            new OAuth2Config(accessTokenTtl: 3600),
        );
    }

    #[Test]
    public function identifierReturnsClientCredentials(): void
    {
        self::assertSame('client_credentials', $this->grant->identifier());
    }

    #[Test]
    public function handleTokenRequestThrowsForPublicClient(): void
    {
        $client = new OAuthClient(
            id: 'public-client',
            name: 'Public App',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: ['api.read'],
            confidential: false,
        );

        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage('Only confidential clients');

        $this->grant->handleTokenRequest($request, $client);
    }

    #[Test]
    public function handleTokenRequestIssuesTokenForConfidentialClient(): void
    {
        $client = new OAuthClient(
            id: 'service-client',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: ['api.read', 'api.write'],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([
            new Scope('api.read'),
            new Scope('api.write'),
        ]);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['scope' => 'api.read api.write'],
        );

        $response = $this->grant->handleTokenRequest($request, $client);

        self::assertSame('Bearer', $response->tokenType);
        self::assertSame(3600, $response->expiresIn);
        self::assertSame(['api.read', 'api.write'], $response->scopes);
        self::assertNotEmpty($response->accessToken);
        self::assertNull($response->refreshToken);
    }

    #[Test]
    public function handleTokenRequestWithEmptyScope(): void
    {
        $client = new OAuthClient(
            id: 'service-client',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([]);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: [],
        );

        $response = $this->grant->handleTokenRequest($request, $client);

        self::assertSame([], $response->scopes);
        self::assertNotEmpty($response->accessToken);
    }

    #[Test]
    public function handleTokenRequestPersistsAccessToken(): void
    {
        $client = new OAuthClient(
            id: 'svc',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([]);

        $tokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $tokenRepo->expects(self::once())->method('persist');

        $grant = new ClientCredentialsGrant($tokenRepo, $this->scopeRepo, $this->auditLogger);

        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $grant->handleTokenRequest($request, $client);
    }

    #[Test]
    public function handleTokenRequestLogsAuditEvent(): void
    {
        $client = new OAuthClient(
            id: 'svc',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([]);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $grant = new ClientCredentialsGrant($this->tokenRepo, $this->scopeRepo, $auditLogger);

        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $grant->handleTokenRequest($request, $client);
    }

    #[Test]
    public function handleTokenRequestWithNullParsedBody(): void
    {
        $client = new OAuthClient(
            id: 'svc',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([]);

        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $response = $this->grant->handleTokenRequest($request, $client);

        self::assertNotEmpty($response->accessToken);
    }

    #[Test]
    public function handleTokenRequestWithNonStringScope(): void
    {
        $client = new OAuthClient(
            id: 'svc',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([]);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['scope' => ['not-a-string']],
        );

        $response = $this->grant->handleTokenRequest($request, $client);

        self::assertSame([], $response->scopes);
    }

    #[Test]
    public function handleTokenRequestSetsSubjectIdToClientId(): void
    {
        $client = new OAuthClient(
            id: 'machine-client',
            name: 'Machine',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([
            new Scope('api.read'),
        ]);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/oauth/token',
            parsedBody: ['scope' => 'api.read'],
        );

        $response = $this->grant->handleTokenRequest($request, $client);

        self::assertSame(['api.read'], $response->scopes);
    }

    #[Test]
    public function handleTokenRequestUsesConfigAccessTokenTtl(): void
    {
        $config = new OAuth2Config(accessTokenTtl: 1800);
        $grant = new ClientCredentialsGrant(
            $this->tokenRepo,
            $this->scopeRepo,
            $this->auditLogger,
            $config,
        );

        $client = new OAuthClient(
            id: 'svc',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([]);

        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $response = $grant->handleTokenRequest($request, $client);

        self::assertSame(1800, $response->expiresIn);
    }

    #[Test]
    public function handleTokenRequestDefaultsTtlFromConfig(): void
    {
        // Default config has accessTokenTtl = 900
        $config = new OAuth2Config();
        $grant = new ClientCredentialsGrant(
            $this->tokenRepo,
            $this->scopeRepo,
            $this->auditLogger,
            $config,
        );

        $client = new OAuthClient(
            id: 'svc',
            name: 'Service',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
        );

        $this->scopeRepo->method('resolveScopes')->willReturn([]);

        $request = new ServerRequest(method: 'POST', uri: '/oauth/token');

        $response = $grant->handleTokenRequest($request, $client);

        self::assertSame(900, $response->expiresIn);
    }
}
