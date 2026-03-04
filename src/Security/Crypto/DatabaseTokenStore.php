<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use PDO;
use PDOException;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;

use function sprintf;

/**
 * Database-backed token store for production use.
 *
 * Stores token→encrypted(original) mappings in a configurable table.
 * The original values are encrypted by the TokenizationService before
 * being passed to this store, so the database never sees plaintext.
 */
#[Api(since: '1.0.0')]
final readonly class DatabaseTokenStore implements TokenStoreInterface
{
    private const string DEFAULT_TABLE = 'token_vault';

    public function __construct(
        private PDO $pdo,
        private string $table = self::DEFAULT_TABLE,
    ) {}

    public function store(string $token, string $encryptedValue, string $context): void
    {
        $sql = sprintf(
            'INSERT INTO %s (token, encrypted_value, context, created_at) VALUES (:token, :encrypted_value, :context, :created_at)',
            $this->table,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':token' => $token,
                ':encrypted_value' => $encryptedValue,
                ':context' => $context,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('Failed to store token: %s', $e->getMessage()),
            );
        }
    }

    public function retrieve(string $token): ?string
    {
        $sql = sprintf(
            'SELECT encrypted_value FROM %s WHERE token = :token LIMIT 1',
            $this->table,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':token' => $token]);

            $result = $stmt->fetchColumn();

            return $result !== false ? (string) $result : null;
        } catch (PDOException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('Failed to retrieve token: %s', $e->getMessage()),
            );
        }
    }

    public function exists(string $token): bool
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE token = :token',
            $this->table,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':token' => $token]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('Failed to check token existence: %s', $e->getMessage()),
            );
        }
    }

    public function remove(string $token): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE token = :token',
            $this->table,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':token' => $token]);
        } catch (PDOException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('Failed to remove token: %s', $e->getMessage()),
            );
        }
    }
}
