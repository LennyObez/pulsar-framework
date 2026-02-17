<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Oidc;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\OAuth2\Oidc\UserInfoEndpoint;
use Pulsar\Extension\OAuth2\Token\AccessToken;

final class UserInfoEndpointTest extends TestCase
{
    private UserInfoEndpoint $endpoint;
    private AccessTokenRepositoryInterface&Stub $tokenRepo;
    private UserClaimsProviderInterface&Stub $claimsProvider;

    protected function setUp(): void
    {
        $this->tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $this->endpoint = new UserInfoEndpoint($this->claimsProvider, $this->tokenRepo);
    }

    #[Test]
    public function getClaimsReturnsNullWhenTokenNotFound(): void
    {
        $this->tokenRepo->method('introspect')->willReturn(null);

        self::assertNull($this->endpoint->getClaims('bad-token'));
    }

    #[Test]
    public function getClaimsReturnsNullWhenTokenIsRevoked(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );
        $this->tokenRepo->method('introspect')->willReturn($token);

        self::assertNull($this->endpoint->getClaims('revoked-token'));
    }

    #[Test]
    public function getClaimsReturnsNullWhenTokenIsExpired(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 hour'),
            issuedAt: new DateTimeImmutable('-2 hours'),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);

        self::assertNull($this->endpoint->getClaims('expired-token'));
    }

    #[Test]
    public function getClaimsReturnsClaimsWithSubForValidToken(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-app',
            subjectId: 'user-42',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);
        $this->claimsProvider->method('getClaims')->willReturn([
            'name' => 'Jane Doe',
            'email' => 'jane@test.com',
        ]);
        $this->claimsProvider->method('getSubjectIdentifier')->willReturn('pairwise-sub-42');

        $claims = $this->endpoint->getClaims('valid-token');

        self::assertNotNull($claims);
        self::assertSame('pairwise-sub-42', $claims['sub']);
        self::assertSame('Jane Doe', $claims['name']);
        self::assertSame('jane@test.com', $claims['email']);
    }

    #[Test]
    public function getClaimsPassesCorrectParametersToProviders(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'my-client',
            subjectId: 'user-99',
            scopes: ['openid', 'email'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);

        $claimsProvider = $this->createMock(UserClaimsProviderInterface::class);
        $claimsProvider->expects(self::once())
            ->method('getClaims')
            ->with('user-99', ['openid', 'email'])
            ->willReturn([]);
        $claimsProvider->expects(self::once())
            ->method('getSubjectIdentifier')
            ->with('user-99', 'my-client')
            ->willReturn('user-99');

        $endpoint = new UserInfoEndpoint($claimsProvider, $this->tokenRepo);
        $endpoint->getClaims('token-val');
    }

    #[Test]
    public function getClaimsSubOverridesProviderSub(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);
        $this->claimsProvider->method('getClaims')->willReturn(['sub' => 'old-sub']);
        $this->claimsProvider->method('getSubjectIdentifier')->willReturn('correct-sub');

        $claims = $this->endpoint->getClaims('token');

        self::assertNotNull($claims);
        self::assertSame('correct-sub', $claims['sub']);
    }
}
