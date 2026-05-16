<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryAccessTokenRepository;

#[CoversClass(InMemoryAccessTokenRepository::class)]
final class InMemoryAccessTokenRepositoryTest extends TestCase
{
    private InMemoryAccessTokenRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryAccessTokenRepository();
    }

    #[Test]
    public function persistAndIntrospect(): void
    {
        $token = new AccessToken(
            id: 'at-mem-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'secret-value',
        );

        $this->repo->persist($token);

        $introspected = $this->repo->introspect('secret-value');

        self::assertNotNull($introspected);
        self::assertSame('at-mem-001', $introspected->id);
        self::assertSame('client-1', $introspected->clientId);
    }

    #[Test]
    public function introspectReturnsNullForUnknownToken(): void
    {
        $result = $this->repo->introspect('nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function introspectReturnsNullForExpiredToken(): void
    {
        $token = new AccessToken(
            id: 'at-mem-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-2 hours'),
            tokenValue: 'expired-value',
        );

        $this->repo->persist($token);

        self::assertNull($this->repo->introspect('expired-value'));
    }

    #[Test]
    public function revokeInvalidatesToken(): void
    {
        $token = new AccessToken(
            id: 'at-mem-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'revoke-me',
        );

        $this->repo->persist($token);
        self::assertNotNull($this->repo->introspect('revoke-me'));

        $this->repo->revoke('at-mem-003');

        self::assertNull($this->repo->introspect('revoke-me'));
        self::assertTrue($this->repo->isRevoked('at-mem-003'));
    }

    #[Test]
    public function revokeBySubjectInvalidatesAllUserTokens(): void
    {
        $this->repo->persist(new AccessToken(
            id: 'at-mem-004',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'user42-token-a',
        ));

        $this->repo->persist(new AccessToken(
            id: 'at-mem-005',
            clientId: 'client-2',
            subjectId: 'user-42',
            scopes: ['api:read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'user42-token-b',
        ));

        $this->repo->revokeBySubject('user-42');

        self::assertNull($this->repo->introspect('user42-token-a'));
        self::assertNull($this->repo->introspect('user42-token-b'));
    }

    #[Test]
    public function isRevokedReturnsFalseForActiveToken(): void
    {
        $token = new AccessToken(
            id: 'at-mem-006',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'active-value',
        );

        $this->repo->persist($token);

        self::assertFalse($this->repo->isRevoked('at-mem-006'));
    }

    #[Test]
    public function tokenStoredByHash(): void
    {
        $tokenValue = 'my-secret-token';
        $token = new AccessToken(
            id: 'at-mem-007',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );

        $this->repo->persist($token);

        // The hash of the token should NOT be usable for introspection
        $hash = hash('sha256', $tokenValue);
        self::assertNull($this->repo->introspect($hash));

        // Only the raw token value works
        self::assertNotNull($this->repo->introspect($tokenValue));
    }
}
