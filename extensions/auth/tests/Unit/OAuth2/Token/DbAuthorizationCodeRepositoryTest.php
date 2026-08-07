<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Auth\OAuth2\Token\AuthorizationCode;
use Pulsar\Extension\Auth\OAuth2\Token\DbAuthorizationCodeRepository;
use Pulsar\Security\Crypto\MasterKey;

use function str_repeat;
use function var_export;

#[CoversClass(DbAuthorizationCodeRepository::class)]
final class DbAuthorizationCodeRepositoryTest extends TestCase
{
    private PdoConnection $connection;

    private DbAuthorizationCodeRepository $repository;

    protected function setUp(): void
    {
        // SQLite in-memory: no file cleanup, fast, isolated per test.
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->repository = new DbAuthorizationCodeRepository(
            $this->connection,
            MasterKey::fromHex(str_repeat('a', 64)),
        );
        $this->repository->installSchema();
    }

    #[Test]
    public function consumeReturnsCodeOnFirstUse(): void
    {
        $code = $this->makeCode('code-1', 'plaintext-code-1');
        $this->repository->persist($code);

        $consumed = $this->repository->consume('plaintext-code-1');

        self::assertNotNull($consumed);
        self::assertSame('code-1', $consumed->id);
        self::assertSame('client-x', $consumed->clientId);
    }

    #[Test]
    public function consumeReturnsNullOnSecondUse(): void
    {
        $code = $this->makeCode('code-2', 'plaintext-code-2');
        $this->repository->persist($code);

        $first = $this->repository->consume('plaintext-code-2');
        $second = $this->repository->consume('plaintext-code-2');

        self::assertNotNull($first);
        self::assertNull($second);
    }

    #[Test]
    public function consumeReturnsNullForExpiredCode(): void
    {
        $code = $this->makeCode(
            'code-3',
            'plaintext-code-3',
            expiresAt: new DateTimeImmutable('-1 minute'),
        );
        $this->repository->persist($code);

        $consumed = $this->repository->consume('plaintext-code-3');

        self::assertNull($consumed);
    }

    #[Test]
    public function consumeReturnsNullForRevokedCode(): void
    {
        $code = $this->makeCode('code-4', 'plaintext-code-4');
        $this->repository->persist($code);
        $this->repository->revoke('code-4');

        $consumed = $this->repository->consume('plaintext-code-4');

        self::assertNull($consumed);
    }

    #[Test]
    public function consumeReturnsNullForUnknownCode(): void
    {
        self::assertNull($this->repository->consume('does-not-exist'));
    }

    #[Test]
    public function isRevokedReturnsTrueAfterRevoke(): void
    {
        $code = $this->makeCode('code-5', 'plaintext-code-5');
        $this->repository->persist($code);

        self::assertFalse($this->repository->isRevoked('code-5'));

        $this->repository->revoke('code-5');

        self::assertTrue($this->repository->isRevoked('code-5'));
    }

    #[Test]
    public function isRevokedReturnsTrueAfterConsume(): void
    {
        $code = $this->makeCode('code-6', 'plaintext-code-6');
        $this->repository->persist($code);

        self::assertFalse($this->repository->isRevoked('code-6'));

        $this->repository->consume('plaintext-code-6');

        self::assertTrue($this->repository->isRevoked('code-6'));
    }

    #[Test]
    public function isRevokedReturnsFalseForUnknownCode(): void
    {
        self::assertFalse($this->repository->isRevoked('does-not-exist'));
    }

    #[Test]
    public function persistRoundTripsScopesAndChallenge(): void
    {
        $code = $this->makeCode(
            'code-7',
            'plaintext-code-7',
            scopes: ['openid', 'profile', 'email'],
            codeChallenge: 'a-pkce-challenge',
        );
        $this->repository->persist($code);

        $consumed = $this->repository->consume('plaintext-code-7');

        self::assertNotNull($consumed);
        self::assertSame(['openid', 'profile', 'email'], $consumed->scopes);
        self::assertSame('a-pkce-challenge', $consumed->codeChallenge);
    }

    #[Test]
    public function consumedCodeNeverReturnsPlaintext(): void
    {
        $code = $this->makeCode('code-8', 'super-secret-plaintext-12345');
        $this->repository->persist($code);

        $consumed = $this->repository->consume('super-secret-plaintext-12345');

        self::assertNotNull($consumed);
        // The repository hashes the code on persist; the hydrated value
        // never re-exposes the plaintext (codeValue stays null).
        self::assertNull($consumed->codeValue);
    }

    #[Test]
    public function persistedRowHoldsNoPlaintextCode(): void
    {
        $this->repository->persist($this->makeCode('code-9', 'super-secret-plaintext-99999'));

        $row = $this->connection->query(
            'SELECT * FROM oauth2_authorization_codes WHERE id = :id',
            ['id' => 'code-9'],
        )->first();

        self::assertNotNull($row);

        // Every column of the stored row, not just code_hash: nothing the
        // repository writes may carry the code itself.
        self::assertStringNotContainsString(
            'super-secret-plaintext-99999',
            var_export($row->toArray(), true),
        );
    }

    #[Test]
    public function codeDigestIsKeyedToTheMasterKey(): void
    {
        // Same table, different master key: the lookup digest must not match,
        // which a domain-separated but unkeyed digest would.
        $foreign = new DbAuthorizationCodeRepository(
            $this->connection,
            MasterKey::fromHex(str_repeat('b', 64)),
        );

        $this->repository->persist($this->makeCode('code-10', 'plaintext-code-10'));

        self::assertNull($foreign->consume('plaintext-code-10'));
        self::assertNotNull($this->repository->consume('plaintext-code-10'));
    }

    /**
     * @param list<string> $scopes
     */
    private function makeCode(
        string $id,
        string $codeValue,
        array $scopes = ['openid'],
        string $codeChallenge = 'challenge',
        ?DateTimeImmutable $expiresAt = null,
    ): AuthorizationCode {
        return new AuthorizationCode(
            id: $id,
            clientId: 'client-x',
            subjectId: 'subject-y',
            redirectUri: 'https://example.com/callback',
            scopes: $scopes,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            expiresAt: $expiresAt ?? new DateTimeImmutable('+10 minutes'),
            issuedAt: new DateTimeImmutable(),
            revoked: false,
            codeValue: $codeValue,
            nonce: null,
        );
    }
}
