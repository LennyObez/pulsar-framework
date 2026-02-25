<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\AuthorizationCode;
use Pulsar\Extension\OAuth2\Token\InMemoryAuthorizationCodeRepository;

final class InMemoryAuthorizationCodeRepositoryTest extends TestCase
{
    private InMemoryAuthorizationCodeRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryAuthorizationCodeRepository();
    }

    private function makeCode(
        string $id = 'code-1',
        string $codeValue = 'raw-code',
        string $expiresIn = '+10 minutes',
    ): AuthorizationCode {
        return new AuthorizationCode(
            id: $id,
            clientId: 'client-1',
            subjectId: 'user-1',
            redirectUri: 'https://example.com/cb',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable($expiresIn),
            issuedAt: new DateTimeImmutable(),
            codeValue: $codeValue,
        );
    }

    #[Test]
    public function persist_and_consume(): void
    {
        $this->repo->persist($this->makeCode());

        $consumed = $this->repo->consume('raw-code');

        self::assertNotNull($consumed);
        self::assertSame('code-1', $consumed->id);
    }

    #[Test]
    public function consume_returns_null_for_unknown_code(): void
    {
        self::assertNull($this->repo->consume('nonexistent'));
    }

    #[Test]
    public function consume_is_one_time_use(): void
    {
        $this->repo->persist($this->makeCode());

        $first = $this->repo->consume('raw-code');
        $second = $this->repo->consume('raw-code');

        self::assertNotNull($first);
        self::assertNull($second, 'Second consumption must return null (one-time use)');
    }

    #[Test]
    public function consume_returns_null_for_expired_code(): void
    {
        $this->repo->persist($this->makeCode(expiresIn: '-1 minute'));

        self::assertNull($this->repo->consume('raw-code'));
    }

    #[Test]
    public function consume_returns_null_for_revoked_code(): void
    {
        $this->repo->persist($this->makeCode());
        $this->repo->revoke('code-1');

        self::assertNull($this->repo->consume('raw-code'));
    }

    #[Test]
    public function revoke_marks_code_as_revoked(): void
    {
        $this->repo->persist($this->makeCode());

        self::assertFalse($this->repo->isRevoked('code-1'));

        $this->repo->revoke('code-1');

        self::assertTrue($this->repo->isRevoked('code-1'));
    }

    #[Test]
    public function is_revoked_also_returns_true_for_consumed_codes(): void
    {
        $this->repo->persist($this->makeCode());
        $this->repo->consume('raw-code');

        self::assertTrue($this->repo->isRevoked('code-1'));
    }

    #[Test]
    public function multiple_codes_are_independent(): void
    {
        $this->repo->persist($this->makeCode('code-1', 'val-1'));
        $this->repo->persist($this->makeCode('code-2', 'val-2'));

        $this->repo->revoke('code-1');

        self::assertNull($this->repo->consume('val-1'));
        self::assertNotNull($this->repo->consume('val-2'));
    }
}
