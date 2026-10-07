<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\AuthorizationCode;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryAuthorizationCodeRepository;

use function print_r;

#[CoversClass(InMemoryAuthorizationCodeRepository::class)]
final class InMemoryAuthorizationCodeRepositoryTest extends TestCase
{
    private InMemoryAuthorizationCodeRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryAuthorizationCodeRepository();
    }

    #[Test]
    public function persistAndConsume(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-mem-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            codeValue: 'code-secret',
        );

        $this->repo->persist($code);

        $consumed = $this->repo->consume('code-secret');

        self::assertNotNull($consumed);
        self::assertSame('ac-mem-001', $consumed->id);
    }

    #[Test]
    public function consumeReturnsNullForUnknownCode(): void
    {
        self::assertNull($this->repo->consume('nonexistent'));
    }

    #[Test]
    public function codeIsOneTimeUse(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-mem-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            codeValue: 'one-time-code',
        );

        $this->repo->persist($code);

        $first = $this->repo->consume('one-time-code');
        self::assertNotNull($first);

        $second = $this->repo->consume('one-time-code');
        self::assertNull($second);
    }

    #[Test]
    public function expiredCodeCannotBeConsumed(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-mem-003',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-11 minutes'),
            codeValue: 'expired-code',
        );

        $this->repo->persist($code);

        self::assertNull($this->repo->consume('expired-code'));
    }

    #[Test]
    public function revokedCodeCannotBeConsumed(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-mem-004',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            codeValue: 'revoked-code',
        );

        $this->repo->persist($code);
        $this->repo->revoke('ac-mem-004');

        self::assertNull($this->repo->consume('revoked-code'));
    }

    #[Test]
    public function isRevokedReturnsTrueAfterConsumption(): void
    {
        $code = new AuthorizationCode(
            id: 'ac-mem-005',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            codeValue: 'consume-me',
        );

        $this->repo->persist($code);

        self::assertFalse($this->repo->isRevoked('ac-mem-005'));

        $this->repo->consume('consume-me');

        // Consumed codes are treated as revoked
        self::assertTrue($this->repo->isRevoked('ac-mem-005'));
    }

    #[Test]
    public function persistedCodeIsNotRetainedInPlaintext(): void
    {
        $codeValue = 'super-secret-code-value';
        $code = new AuthorizationCode(
            id: 'ac-mem-006',
            clientId: 'client-1',
            subjectId: 'user-42',
            redirectUri: 'https://app.example.com/callback',
            scopes: ['openid'],
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            expiresAt: new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            codeValue: $codeValue,
        );

        $this->repo->persist($code);

        $consumed = $this->repo->consume($codeValue);
        self::assertNotNull($consumed);
        self::assertNull($consumed->codeValue);

        // print_r walks real properties (unlike var_dump it ignores
        // __debugInfo), so this fails if any part of the repository's state
        // still holds the raw code.
        self::assertStringNotContainsString($codeValue, print_r($this->repo, true));
    }
}
