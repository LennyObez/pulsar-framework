<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryAccessTokenRepository;
use ReflectionProperty;

use function array_keys;
use function hash;
use function print_r;
use function sodium_bin2hex;
use function sodium_crypto_generichash;

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
    public function persistedTokenIsNotRetainedInPlaintext(): void
    {
        $tokenValue = 'my-secret-token';

        $this->repo->persist($this->makeToken('at-mem-007', $tokenValue));

        $introspected = $this->repo->introspect($tokenValue);
        self::assertNotNull($introspected);
        self::assertNull($introspected->tokenValue);

        // print_r walks real properties (unlike var_dump it ignores
        // __debugInfo), so this fails if any part of the repository's state
        // still holds the raw token.
        self::assertStringNotContainsString($tokenValue, print_r($this->repo, true));
    }

    #[Test]
    public function lookupIndexIsNotABareDigestOfTheToken(): void
    {
        $tokenValue = 'my-secret-token';

        $this->repo->persist($this->makeToken('at-mem-008', $tokenValue));

        // A bare digest of the token is not a lookup key: an attacker holding
        // a leaked index entry cannot introspect with it.
        self::assertNull($this->repo->introspect(hash('sha256', $tokenValue)));
        self::assertNull($this->repo->introspect(
            sodium_bin2hex(sodium_crypto_generichash($tokenValue, '', 32)),
        ));

        // Only the raw token value works
        self::assertNotNull($this->repo->introspect($tokenValue));
    }

    #[Test]
    public function lookupIndexIsKeyedPerInstance(): void
    {
        $tokenValue = 'my-secret-token';

        $other = new InMemoryAccessTokenRepository();

        $this->repo->persist($this->makeToken('at-mem-009', $tokenValue));
        $other->persist($this->makeToken('at-mem-009', $tokenValue));

        $property = new ReflectionProperty(InMemoryAccessTokenRepository::class, 'hashIndex');

        /** @var array<string, string> $mine */
        $mine = $property->getValue($this->repo);
        /** @var array<string, string> $theirs */
        $theirs = $property->getValue($other);

        // Same token, same class: identical digests would mean the index key
        // is a compiled-in constant rather than per-instance material.
        self::assertNotSame(array_keys($mine), array_keys($theirs));
    }

    private function makeToken(string $id, string $tokenValue): AccessToken
    {
        return new AccessToken(
            id: $id,
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
    }
}
