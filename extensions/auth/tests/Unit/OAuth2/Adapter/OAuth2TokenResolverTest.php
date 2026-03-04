<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Adapter\OAuth2TokenResolver;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;

final class OAuth2TokenResolverTest extends TestCase
{
    #[Test]
    public function resolveReturnsIdentityForValidToken(): void
    {
        $accessToken = new AccessToken(
            id: 'at-1',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn($accessToken);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $claimsProvider->method('getClaims')->willReturn(['name' => 'Jane Doe']);

        $resolver = new OAuth2TokenResolver($tokenRepo, $claimsProvider);
        $identity = $resolver->resolve('bearer-token-abc');

        self::assertNotNull($identity);
        self::assertSame('user-42', $identity->id());
        self::assertSame('Jane Doe', $identity->displayName());
        self::assertSame('client-1', $identity->attribute('oauth2_client_id'));
    }

    #[Test]
    public function resolveReturnsNullForUnknownToken(): void
    {
        $tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn(null);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);

        $resolver = new OAuth2TokenResolver($tokenRepo, $claimsProvider);

        self::assertNull($resolver->resolve('invalid-token'));
    }

    #[Test]
    public function resolveReturnsNullForExpiredToken(): void
    {
        $accessToken = new AccessToken(
            id: 'at-expired',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 hour'),
            issuedAt: new DateTimeImmutable('-2 hours'),
        );

        $tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn($accessToken);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);

        $resolver = new OAuth2TokenResolver($tokenRepo, $claimsProvider);

        self::assertNull($resolver->resolve('expired-token'));
    }

    #[Test]
    public function resolveUsesPreferredUsernameWhenNameMissing(): void
    {
        $accessToken = new AccessToken(
            id: 'at-2',
            clientId: 'client-1',
            subjectId: 'user-99',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn($accessToken);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $claimsProvider->method('getClaims')->willReturn(['preferred_username' => 'jdoe']);

        $resolver = new OAuth2TokenResolver($tokenRepo, $claimsProvider);
        $identity = $resolver->resolve('token');

        self::assertNotNull($identity);
        self::assertSame('jdoe', $identity->displayName());
    }

    #[Test]
    public function resolveUsesSubjectIdWhenNoNameClaims(): void
    {
        $accessToken = new AccessToken(
            id: 'at-3',
            clientId: 'client-1',
            subjectId: 'user-anon',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokenRepo->method('introspect')->willReturn($accessToken);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $claimsProvider->method('getClaims')->willReturn([]);

        $resolver = new OAuth2TokenResolver($tokenRepo, $claimsProvider);
        $identity = $resolver->resolve('token');

        self::assertNotNull($identity);
        self::assertSame('user-anon', $identity->displayName());
    }
}
