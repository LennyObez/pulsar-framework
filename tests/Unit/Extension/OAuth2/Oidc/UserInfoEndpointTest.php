<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Oidc;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\OAuth2\Oidc\UserInfoEndpoint;
use Pulsar\Extension\OAuth2\Token\AccessToken;

#[CoversClass(UserInfoEndpoint::class)]
final class UserInfoEndpointTest extends TestCase
{
    #[Test]
    public function returnsClaimsForValidToken(): void
    {
        $token = new AccessToken(
            id: 'at-ui-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid', 'email'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'bearer-token',
        );

        $tokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $tokenRepo->expects($this->any())->method('introspect')
            ->with('bearer-token')
            ->willReturn($token);

        $claimsProvider = $this->createMock(UserClaimsProviderInterface::class);
        $claimsProvider->expects($this->any())->method('getClaims')
            ->with('user-42', ['openid', 'email'])
            ->willReturn(['email' => 'john@example.com', 'email_verified' => true]);
        $claimsProvider->expects($this->any())->method('getSubjectIdentifier')
            ->with('user-42', 'client-1')
            ->willReturn('user-42');

        $endpoint = new UserInfoEndpoint($claimsProvider, $tokenRepo);

        $claims = $endpoint->getClaims('bearer-token');

        self::assertNotNull($claims);
        self::assertSame('user-42', $claims['sub']);
        self::assertSame('john@example.com', $claims['email']);
        self::assertTrue($claims['email_verified']);
    }

    #[Test]
    public function returnsNullForInvalidToken(): void
    {
        $tokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $tokenRepo->expects($this->any())->method('introspect')
            ->with('invalid-token')
            ->willReturn(null);

        $claimsProvider = $this->createMock(UserClaimsProviderInterface::class);

        $endpoint = new UserInfoEndpoint($claimsProvider, $tokenRepo);

        $claims = $endpoint->getClaims('invalid-token');

        self::assertNull($claims);
    }

    #[Test]
    public function returnsNullForRevokedToken(): void
    {
        $token = new AccessToken(
            id: 'at-ui-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            revoked: true,
        );

        $tokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        // The repository returns null for revoked/inactive tokens
        $tokenRepo->method('introspect')->willReturn(null);

        $claimsProvider = $this->createMock(UserClaimsProviderInterface::class);

        $endpoint = new UserInfoEndpoint($claimsProvider, $tokenRepo);

        $claims = $endpoint->getClaims('revoked-token');

        self::assertNull($claims);
    }

    #[Test]
    public function alwaysIncludesSubClaim(): void
    {
        $token = new AccessToken(
            id: 'at-ui-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'token-sub',
        );

        $tokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn($token);

        $claimsProvider = $this->createMock(UserClaimsProviderInterface::class);
        $claimsProvider->expects($this->any())->method('getClaims')->willReturn([]);
        $claimsProvider->expects($this->any())->method('getSubjectIdentifier')
            ->with('user-42', 'client-1')
            ->willReturn('pairwise-sub-42');

        $endpoint = new UserInfoEndpoint($claimsProvider, $tokenRepo);

        $claims = $endpoint->getClaims('token-sub');

        self::assertNotNull($claims);
        self::assertSame('pairwise-sub-42', $claims['sub']);
    }

    #[Test]
    public function claimsConsistentWithScopes(): void
    {
        $token = new AccessToken(
            id: 'at-ui-004',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'token-profile',
        );

        $tokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn($token);

        $claimsProvider = $this->createMock(UserClaimsProviderInterface::class);
        $claimsProvider->expects($this->any())->method('getClaims')
            ->with('user-42', ['openid', 'profile'])
            ->willReturn([
                'name' => 'John Doe',
                'preferred_username' => 'johnd',
            ]);
        $claimsProvider->expects($this->any())->method('getSubjectIdentifier')->willReturn('user-42');

        $endpoint = new UserInfoEndpoint($claimsProvider, $tokenRepo);

        $claims = $endpoint->getClaims('token-profile');

        self::assertNotNull($claims);
        self::assertSame('John Doe', $claims['name']);
        self::assertSame('johnd', $claims['preferred_username']);
        self::assertSame('user-42', $claims['sub']);
    }
}
