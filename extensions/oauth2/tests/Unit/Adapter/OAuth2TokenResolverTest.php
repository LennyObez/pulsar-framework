<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\OAuth2\Adapter\OAuth2TokenResolver;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\OAuth2\Token\AccessToken;

final class OAuth2TokenResolverTest extends TestCase
{
    private OAuth2TokenResolver $resolver;
    private AccessTokenRepositoryInterface&Stub $tokenRepo;
    private UserClaimsProviderInterface&Stub $claimsProvider;

    protected function setUp(): void
    {
        $this->tokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $this->resolver = new OAuth2TokenResolver($this->tokenRepo, $this->claimsProvider);
    }

    #[Test]
    public function resolveReturnsNullWhenTokenNotFound(): void
    {
        $this->tokenRepo->method('introspect')->willReturn(null);

        self::assertNull($this->resolver->resolve('invalid-token'));
    }

    #[Test]
    public function resolveReturnsNullWhenTokenIsRevoked(): void
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

        self::assertNull($this->resolver->resolve('some-token'));
    }

    #[Test]
    public function resolveReturnsNullWhenTokenIsExpired(): void
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

        self::assertNull($this->resolver->resolve('expired-token'));
    }

    #[Test]
    public function resolveReturnsIdentityWithNameClaim(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);
        $this->claimsProvider->method('getClaims')->willReturn(['name' => 'John Doe']);

        $identity = $this->resolver->resolve('valid-token');

        self::assertNotNull($identity);
        self::assertSame('user-42', $identity->id());
        self::assertSame('John Doe', $identity->displayName());
        self::assertSame('client-1', $identity->attribute('oauth2_client_id'));
        self::assertSame(['openid', 'profile'], $identity->attribute('oauth2_scopes'));
        self::assertSame('tok-1', $identity->attribute('oauth2_token_id'));
    }

    #[Test]
    public function resolveUsesPreferredUsernameWhenNoName(): void
    {
        $token = new AccessToken(
            id: 'tok-2',
            clientId: 'client-1',
            subjectId: 'user-99',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);
        $this->claimsProvider->method('getClaims')->willReturn([
            'preferred_username' => 'jdoe',
        ]);

        $identity = $this->resolver->resolve('token-val');

        self::assertNotNull($identity);
        self::assertSame('jdoe', $identity->displayName());
    }

    #[Test]
    public function resolveFallsBackToSubjectIdWhenNoNameClaims(): void
    {
        $token = new AccessToken(
            id: 'tok-3',
            clientId: 'client-1',
            subjectId: 'user-77',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);
        $this->claimsProvider->method('getClaims')->willReturn([]);

        $identity = $this->resolver->resolve('token-val');

        self::assertNotNull($identity);
        self::assertSame('user-77', $identity->displayName());
    }

    #[Test]
    public function resolveIgnoresNonStringNameClaim(): void
    {
        $token = new AccessToken(
            id: 'tok-4',
            clientId: 'client-1',
            subjectId: 'user-55',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);
        $this->claimsProvider->method('getClaims')->willReturn([
            'name' => 123,
            'preferred_username' => ['array'],
        ]);

        $identity = $this->resolver->resolve('token-val');

        self::assertNotNull($identity);
        self::assertSame('user-55', $identity->displayName());
    }

    #[Test]
    public function resolvePassesCorrectScopesToClaimsProvider(): void
    {
        $token = new AccessToken(
            id: 'tok-5',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['openid', 'profile', 'email'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);

        $claimsProvider = $this->createMock(UserClaimsProviderInterface::class);
        $claimsProvider->expects(self::once())
            ->method('getClaims')
            ->with('user-1', ['openid', 'profile', 'email'])
            ->willReturn(['name' => 'Test']);

        $resolver = new OAuth2TokenResolver($this->tokenRepo, $claimsProvider);
        $resolver->resolve('token-val');
    }

    #[Test]
    public function resolveDefaultsTwoFactorDisabledWhenNoMfaClaims(): void
    {
        // F385.7: when the IdP omits both `amr` and `acr`, the resolver
        // must NOT silently grant 2FA bypass — fall back to Disabled so
        // step-up middleware refuses the token.
        $identity = $this->resolveWithClaims(['name' => 'No MFA User']);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveMarksVerifiedWhenAmrContainsMfa(): void
    {
        $identity = $this->resolveWithClaims(['amr' => ['pwd', 'mfa']]);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Verified, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveMarksVerifiedWhenAmrContainsOtp(): void
    {
        $identity = $this->resolveWithClaims(['amr' => ['otp']]);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Verified, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveMarksVerifiedWhenAmrContainsFido(): void
    {
        $identity = $this->resolveWithClaims(['amr' => ['fido']]);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Verified, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveStaysDisabledWhenAmrOnlyHasPasswordFactor(): void
    {
        // `pwd` alone is single-factor; not a step-up qualifier.
        $identity = $this->resolveWithClaims(['amr' => ['pwd']]);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveStaysDisabledWhenAmrIsNotAList(): void
    {
        // Defensive: a malformed `amr` (string instead of list) must
        // not be misinterpreted as MFA.
        $identity = $this->resolveWithClaims(['amr' => 'mfa']);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveMarksVerifiedWhenAcrIsLevel2OrHigher(): void
    {
        $identity = $this->resolveWithClaims(['acr' => '2']);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Verified, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveMarksVerifiedWhenAcrIsIncommonSilver(): void
    {
        $identity = $this->resolveWithClaims(['acr' => 'urn:mace:incommon:iap:silver']);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Verified, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveStaysDisabledForLevel1Acr(): void
    {
        $identity = $this->resolveWithClaims(['acr' => '1']);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
    }

    #[Test]
    public function resolveStaysDisabledForUnknownAcrUrn(): void
    {
        $identity = $this->resolveWithClaims(['acr' => 'urn:example:custom:level']);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function resolveWithClaims(array $claims): ?\Pulsar\Auth\Identity\IdentityInterface
    {
        $token = new AccessToken(
            id: 'tok-mfa',
            clientId: 'client-1',
            subjectId: 'user-mfa',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );
        $this->tokenRepo->method('introspect')->willReturn($token);
        $this->claimsProvider->method('getClaims')->willReturn($claims);

        return $this->resolver->resolve('mfa-token');
    }
}
