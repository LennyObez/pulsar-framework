<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use PDO;
use PDOException;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;

use function preg_match;
use function sprintf;

/**
 * Database-backed token store for production use.
 *
 * Stores token→encrypted(original) mappings in a configurable table.
 * The original values are encrypted by the TokenizationService before
 * being passed to this store, so the database never sees plaintext.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DatabaseTokenStore implements TokenStoreInterface
{
    private const string DEFAULT_TABLE = 'token_vault';

    /**
     * SQL identifier pattern: letters, digits, underscores, 1-63 chars,
     * must start with a letter or underscore. Matches PostgreSQL,
     * MySQL, and SQLite identifier rules.
     */
    private const string TABLE_NAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    public function __construct(
        private PDO $pdo,
        private string $table = self::DEFAULT_TABLE,
    ) {
        // Validate the table name at construction time. The table name is
        // interpolated directly into SQL via sprintf() in every query, so
        // any characters outside the SQL identifier character set would
        // permit SQL injection through a misconfigured dependency.
        if (preg_match(self::TABLE_NAME_PATTERN, $this->table) !== 1) {
            throw SecurityException::encryptionFailed(sprintf(
                'Invalid token store table name %s: must match SQL identifier pattern',
                var_export($this->table, true),
            ));
        }
    }

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

            return is_string($result) ? $result : null;
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
