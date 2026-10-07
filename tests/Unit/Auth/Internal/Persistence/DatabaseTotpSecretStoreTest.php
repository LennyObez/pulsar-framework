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
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use RuntimeException;
use Throwable;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(DatabaseTotpSecretStore::class)]
final class DatabaseTotpSecretStoreTest extends TestCase
{
    /**
     * The derivation AuthWiring.php:190 gives the production store. Repeating it
     * here is what makes the at-rest assertions below evidence for ASVS 6.1: the
     * column is opened by the key the framework actually uses, not by a key the
     * test invented.
     */
    private const int TOTP_SUB_KEY_ID = 4;
    private const string TOTP_KDF_CONTEXT = 'totpscrt';

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
        $secret = 'JBSWY3DPEHPK3PXP';

        $this->store->store('user-1', $secret);

        $raw = $this->rawColumn('user-1');

        // "Not equal to the plaintext" would also pass for base64, hex or a
        // truncation, so each of those is ruled out explicitly, including inside
        // the base64 envelope the Encryptor wraps its ciphertext in.
        self::assertStringNotContainsString($secret, $raw);
        self::assertStringNotContainsString(base64_encode($secret), $raw);
        self::assertStringNotContainsString(bin2hex($secret), $raw);

        $envelope = base64_decode($raw, true);
        self::assertIsString($envelope, 'Column is not the base64 envelope the Encryptor writes');
        self::assertStringNotContainsString($secret, $envelope);

        // And it is ciphertext rather than a digest: the production key opens it.
        self::assertSame($secret, $this->encryptor->decrypt($raw));
    }

    #[Test]
    public function store_writes_a_fresh_nonce_so_a_repeated_secret_is_not_recognisable(): void
    {
        $this->store->store('user-1', 'JBSWY3DPEHPK3PXP');
        $first = $this->rawColumn('user-1');

        $this->store->store('user-1', 'JBSWY3DPEHPK3PXP');
        $second = $this->rawColumn('user-1');

        // Deterministic ciphertext would let anyone holding the table tell which
        // accounts share a seed without holding the key.
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function stored_secret_does_not_open_under_a_different_master_key(): void
    {
        $this->store->store('user-1', 'JBSWY3DPEHPK3PXP');

        $foreign = Encryptor::fromDerivedKey(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
            self::TOTP_SUB_KEY_ID,
            self::TOTP_KDF_CONTEXT,
        );

        $this->expectException(SecurityException::class);

        $foreign->decrypt($this->rawColumn('user-1'));
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
     * The shipped Encryptor under a subkey derived exactly as the composition
     * root derives it. A stub encryptor here would leave the ASVS 6.1 claim
     * resting on the stub rather than on the framework's at-rest control.
     */
    private function createEncryptor(): EncryptorInterface
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        return Encryptor::fromDerivedKey($masterKey, self::TOTP_SUB_KEY_ID, self::TOTP_KDF_CONTEXT);
    }

    /**
     * Read the stored column without going through the store, so the assertion
     * sees what the database sees.
     */
    private function rawColumn(string $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT encrypted_secret FROM auth_totp_secrets WHERE user_id = ?');
        $stmt->execute([$userId]);

        /** @var mixed $raw */
        $raw = $stmt->fetchColumn();
        self::assertIsString($raw, 'No row was written for ' . $userId);

        return $raw;
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

            public function variant(): \Pulsar\Database\DriverVariant
            {
                return \Pulsar\Database\DriverVariant::Standard;
            }

            public function dialect(): \Pulsar\Database\Dialect\DialectInterface
            {
                return \Pulsar\Database\Dialect\Dialects::for($this->driver(), $this->variant());
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
