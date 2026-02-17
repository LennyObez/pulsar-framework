<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Internal\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Internal\Persistence\DatabaseTotpSecretStore;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use RuntimeException;
use Throwable;

#[CoversClass(DatabaseTotpSecretStore::class)]
final class DatabaseTotpSecretStoreTest extends TestCase
{
    private PDO $pdo;
    private EncryptorInterface $encryptor;
    private DatabaseTotpSecretStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE auth_totp_secrets (
                user_id VARCHAR(36) PRIMARY KEY,
                encrypted_secret TEXT NOT NULL,
                algorithm VARCHAR(10) NOT NULL DEFAULT 'sha1',
                digits INTEGER NOT NULL DEFAULT 6,
                period INTEGER NOT NULL DEFAULT 30,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
            SQL);

        $this->encryptor = $this->createEncryptor();

        $connection = $this->createConnectionStub();
        $this->store = new DatabaseTotpSecretStore($connection, $this->encryptor);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS auth_totp_secrets');
    }

    #[Test]
    public function retrieve_returns_null_for_unknown_identity(): void
    {
        self::assertNull($this->store->retrieve('unknown-user'));
    }

    #[Test]
    public function store_and_retrieve_returns_decrypted_secret(): void
    {
        $this->store->store('user-1', 'JBSWY3DPEHPK3PXP');

        $retrieved = $this->store->retrieve('user-1');

        self::assertSame('JBSWY3DPEHPK3PXP', $retrieved);
    }

    #[Test]
    public function store_encrypts_secret_before_persisting(): void
    {
        $this->store->store('user-1', 'JBSWY3DPEHPK3PXP');

        // Read raw from the database to verify it is NOT plaintext
        $stmt = $this->pdo->prepare('SELECT encrypted_secret FROM auth_totp_secrets WHERE user_id = ?');
        $stmt->execute(['user-1']);
        $raw = $stmt->fetchColumn();

        self::assertIsString($raw);
        self::assertNotSame('JBSWY3DPEHPK3PXP', $raw, 'Secret must be encrypted at rest');
    }

    #[Test]
    public function store_overwrites_existing_secret(): void
    {
        $this->store->store('user-1', 'secret-1');
        $this->store->store('user-1', 'secret-2');

        self::assertSame('secret-2', $this->store->retrieve('user-1'));

        // Verify exactly one row exists
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM auth_totp_secrets WHERE user_id = ?');
        $stmt->execute(['user-1']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function delete_removes_stored_secret(): void
    {
        $this->store->store('user-1', 'some-secret');
        $this->store->delete('user-1');

        self::assertNull($this->store->retrieve('user-1'));
    }

    #[Test]
    public function delete_is_safe_for_unknown_identity(): void
    {
        // Should not throw
        $this->store->delete('non-existent');

        self::assertNull($this->store->retrieve('non-existent'));
    }

    #[Test]
    public function different_identities_are_isolated(): void
    {
        $this->store->store('user-1', 'secret-a');
        $this->store->store('user-2', 'secret-b');

        self::assertSame('secret-a', $this->store->retrieve('user-1'));
        self::assertSame('secret-b', $this->store->retrieve('user-2'));
    }

    #[Test]
    public function delete_one_identity_does_not_affect_another(): void
    {
        $this->store->store('user-1', 'secret-a');
        $this->store->store('user-2', 'secret-b');

        $this->store->delete('user-1');

        self::assertNull($this->store->retrieve('user-1'));
        self::assertSame('secret-b', $this->store->retrieve('user-2'));
    }

    #[Test]
    public function store_sets_timestamps(): void
    {
        $this->store->store('user-1', 'secret');

        $stmt = $this->pdo->prepare('SELECT created_at, updated_at FROM auth_totp_secrets WHERE user_id = ?');
        $stmt->execute(['user-1']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertNotEmpty($row['created_at']);
        self::assertNotEmpty($row['updated_at']);
    }

    /**
     * Create a real encryptor using sodium for actual encrypt/decrypt.
     *
     * Uses a deterministic test key to avoid relying on environment variables.
     */
    private function createEncryptor(): EncryptorInterface
    {
        $key = str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        return new class ($key) implements EncryptorInterface {
            public function __construct(
                private readonly string $key,
            ) {}

            public function encrypt(string $plaintext): string
            {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

                return base64_encode($nonce . $ciphertext);
            }

            public function decrypt(string $encoded): string
            {
                $decoded = base64_decode($encoded, true);
                if ($decoded === false) {
                    throw new RuntimeException('Invalid base64');
                }

                $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

                $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
                if ($plaintext === false) {
                    throw new RuntimeException('Decryption failed');
                }

                return $plaintext;
            }

            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): EncryptorInterface
            {
                return $this;
            }
        };
    }

    /**
     * Create a ConnectionInterface stub that delegates to the real PDO.
     */
    private function createConnectionStub(): ConnectionInterface
    {
        $pdo = $this->pdo;

        return new class ($pdo) implements ConnectionInterface {
            public function __construct(private readonly PDO $pdo) {}

            public function query(string $sql, array $bindings = []): Result
            {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindings);
                /** @var list<array<string, mixed>> $rows */
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return Result::fromArrays($rows);
            }

            public function execute(string $sql, array $bindings = []): int
            {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindings);

                return $stmt->rowCount();
            }

            public function prepare(string $sql): \Pulsar\Database\Statement
            {
                throw new RuntimeException('Not implemented');
            }

            public function beginTransaction(): \Pulsar\Database\Transaction
            {
                throw new RuntimeException('Not implemented');
            }

            public function transaction(callable $callback): mixed
            {
                $this->pdo->beginTransaction();
                try {
                    $result = $callback($this);
                    $this->pdo->commit();
                    return $result;
                } catch (Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }

            public function lastInsertId(): string
            {
                return $this->pdo->lastInsertId() ?: '0';
            }

            public function driver(): Driver
            {
                return Driver::SQLite;
            }

            public function name(): string
            {
                return 'test';
            }

            public function inTransaction(): bool
            {
                return $this->pdo->inTransaction();
            }

            public function disconnect(): void {}
        };
    }
}
