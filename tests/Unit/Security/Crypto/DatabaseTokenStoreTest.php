<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\DatabaseTokenStore;

#[CoversClass(DatabaseTokenStore::class)]
final class DatabaseTokenStoreTest extends TestCase
{
    private PDO $pdo;
    private DatabaseTokenStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE token_vault (
                token TEXT PRIMARY KEY,
                encrypted_value TEXT NOT NULL,
                context TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );

        $this->store = new DatabaseTokenStore($this->pdo);
    }

    #[Test]
    public function storeAndRetrieve(): void
    {
        $this->store->store('tok_db1', 'encrypted_data', 'pan');

        self::assertSame('encrypted_data', $this->store->retrieve('tok_db1'));
    }

    #[Test]
    public function retrieveReturnsNullForUnknownToken(): void
    {
        self::assertNull($this->store->retrieve('tok_nonexistent'));
    }

    #[Test]
    public function existsReturnsTrueForStoredToken(): void
    {
        $this->store->store('tok_db2', 'data', 'ssn');

        self::assertTrue($this->store->exists('tok_db2'));
    }

    #[Test]
    public function existsReturnsFalseForUnknownToken(): void
    {
        self::assertFalse($this->store->exists('tok_missing'));
    }

    #[Test]
    public function removeDeletesToken(): void
    {
        $this->store->store('tok_db3', 'data', 'ctx');
        self::assertTrue($this->store->exists('tok_db3'));

        $this->store->remove('tok_db3');

        self::assertFalse($this->store->exists('tok_db3'));
        self::assertNull($this->store->retrieve('tok_db3'));
    }

    #[Test]
    public function removeNonexistentTokenIsNoOp(): void
    {
        // Should not throw
        $this->store->remove('tok_never_existed');

        self::assertFalse($this->store->exists('tok_never_existed'));
    }

    #[Test]
    public function multipleTokensAreIndependent(): void
    {
        $this->store->store('tok_a', 'enc_1', 'pan');
        $this->store->store('tok_b', 'enc_2', 'email');

        self::assertSame('enc_1', $this->store->retrieve('tok_a'));
        self::assertSame('enc_2', $this->store->retrieve('tok_b'));

        $this->store->remove('tok_a');

        self::assertNull($this->store->retrieve('tok_a'));
        self::assertSame('enc_2', $this->store->retrieve('tok_b'));
    }

    #[Test]
    public function customTableName(): void
    {
        $this->pdo->exec(
            'CREATE TABLE custom_tokens (
                token TEXT PRIMARY KEY,
                encrypted_value TEXT NOT NULL,
                context TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );

        $store = new DatabaseTokenStore($this->pdo, 'custom_tokens');
        $store->store('tok_custom', 'data', 'ctx');

        self::assertSame('data', $store->retrieve('tok_custom'));

        // Original table should not contain the token
        self::assertFalse($this->store->exists('tok_custom'));
    }

    #[Test]
    public function storeRecordsCreatedAt(): void
    {
        $this->store->store('tok_ts', 'data', 'ctx');

        $stmt = $this->pdo->prepare('SELECT created_at FROM token_vault WHERE token = :token');
        $stmt->execute([':token' => 'tok_ts']);
        $createdAt = $stmt->fetchColumn();

        self::assertNotFalse($createdAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $createdAt);
    }

    #[Test]
    public function storeRecordsContext(): void
    {
        $this->store->store('tok_ctx', 'data', 'pan');

        $stmt = $this->pdo->prepare('SELECT context FROM token_vault WHERE token = :token');
        $stmt->execute([':token' => 'tok_ctx']);
        $context = $stmt->fetchColumn();

        self::assertSame('pan', $context);
    }
}
