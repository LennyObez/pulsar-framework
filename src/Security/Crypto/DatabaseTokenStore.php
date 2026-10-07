<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Security\Exception\SecurityException;

use function date;
use function preg_match;
use function sprintf;

/**
 * Database-backed token store for production use.
 *
 * Stores token→encrypted(original) mappings in a configurable table. The original
 * values are encrypted by the TokenizationService before being passed here, so the
 * database never sees plaintext.
 *
 * Takes a ConnectionInterface rather than a raw PDO. That is not a style preference:
 * PdoConnection keeps its PDO private and nothing in the framework hands one out, so
 * the previous signature could not be satisfied from the container. This class was
 * therefore never wired anywhere, while PciDssMapping cited it by name as the
 * production persistence behind Req 3.4 and reported the control Implemented — a
 * compliance claim resting on a class no deployment could construct.
 *
 * Going through ConnectionInterface also means tokens inherit the connection the rest
 * of the framework uses, including its TLS settings, rather than a PDO assembled
 * somewhere else under different rules.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DatabaseTokenStore implements TokenStoreInterface
{
    private const string DEFAULT_TABLE = 'token_vault';

    /**
     * SQL identifier pattern: letters, digits, underscores, 1-63 chars, must start
     * with a letter or underscore. Matches PostgreSQL, MySQL, and SQLite rules.
     */
    private const string TABLE_NAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    public function __construct(
        private ConnectionInterface $connection,
        private string $table = self::DEFAULT_TABLE,
    ) {
        // Validated at construction because the table name is interpolated into SQL
        // via sprintf() in every query below: anything outside the identifier
        // character set would be an injection point through a misconfigured
        // dependency rather than through user input.
        if (preg_match(self::TABLE_NAME_PATTERN, $this->table) !== 1) {
            throw SecurityException::encryptionFailed(sprintf(
                'Invalid token store table name %s: must match SQL identifier pattern',
                $this->table,
            ));
        }
    }

    public function store(string $token, string $encryptedValue, string $context): void
    {
        $sql = sprintf(
            'INSERT INTO %s (token, encrypted_value, context, created_at) '
            . 'VALUES (:token, :encrypted_value, :context, :created_at)',
            $this->table,
        );

        try {
            $this->connection->execute($sql, [
                'token' => $token,
                'encrypted_value' => $encryptedValue,
                'context' => $context,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (DatabaseException $e) {
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
            $row = $this->connection->query($sql, ['token' => $token])->first();

            return $row?->getNullableString('encrypted_value');
        } catch (DatabaseException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('Failed to retrieve token: %s', $e->getMessage()),
            );
        }
    }

    public function exists(string $token): bool
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS total FROM %s WHERE token = :token',
            $this->table,
        );

        try {
            $row = $this->connection->query($sql, ['token' => $token])->first();

            return $row !== null && $row->getInt('total') > 0;
        } catch (DatabaseException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('Failed to check token existence: %s', $e->getMessage()),
            );
        }
    }

    public function remove(string $token): void
    {
        $sql = sprintf('DELETE FROM %s WHERE token = :token', $this->table);

        try {
            $this->connection->execute($sql, ['token' => $token]);
        } catch (DatabaseException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('Failed to remove token: %s', $e->getMessage()),
            );
        }
    }
}
