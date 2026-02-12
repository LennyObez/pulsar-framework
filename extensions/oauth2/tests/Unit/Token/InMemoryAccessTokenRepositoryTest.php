<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\AccessToken;
use Pulsar\Extension\OAuth2\Token\InMemoryAccessTokenRepository;

final class InMemoryAccessTokenRepositoryTest extends TestCase
{
    private InMemoryAccessTokenRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryAccessTokenRepository();
    }

    #[Test]
    public function persist_and_introspect_by_token_value(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'raw-token-value',
        );

        $this->repo->persist($token);

        $result = $this->repo->introspect('raw-token-value');
        self::assertNotNull($result);
        self::assertSame('tok-1', $result->id);
    }

    #[Test]
    public function introspect_returns_null_for_unknown_token(): void
    {
        self::assertNull($this->repo->introspect('nonexistent'));
    }

    #[Test]
    public function introspect_returns_null_for_revoked_token(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'secret',
        );

        $this->repo->persist($token);
        $this->repo->revoke('tok-1');

        self::assertNull($this->repo->introspect('secret'));
    }

    #[Test]
    public function revoke_marks_token_as_revoked(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'secret',
        );

        $this->repo->persist($token);
        self::assertFalse($this->repo->isRevoked('tok-1'));

        $this->repo->revoke('tok-1');
        self::assertTrue($this->repo->isRevoked('tok-1'));
    }

    #[Test]
    public function revoke_by_subject_revokes_all_tokens_for_subject(): void
    {
        $token1 = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 'user-1',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'val-1',
        );
        $token2 = new AccessToken(
            id: 'tok-2',
            clientId: 'c',
            subjectId: 'user-1',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'val-2',
        );
        $token3 = new AccessToken(
            id: 'tok-3',
            clientId: 'c',
            subjectId: 'user-2',
            scopes: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'val-3',
        );

        $this->repo->persist($token1);
        $this->repo->persist($token2);
        $this->repo->persist($token3);

        $this->repo->revokeBySubject('user-1');

        self::assertTrue($this->repo->isRevoked('tok-1'));
        self::assertTrue($this->repo->isRevoked('tok-2'));
        self::assertFalse($this->repo->isRevoked('tok-3'));
    }

    #[Test]
    public function introspect_returns_null_for_expired_token(): void
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'c',
            subjectId: 's',
            scopes: [],
            expiresAt: new DateTimeImmutable('-1 hour'),
            issuedAt: new DateTimeImmutable('-2 hours'),
            tokenValue: 'expired-secret',
        );

        $this->repo->persist($token);

        self::assertNull($this->repo->introspect('expired-secret'));
    }
}
