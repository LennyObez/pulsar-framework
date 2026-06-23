<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Handler;

use InvalidArgumentException;
use Override;
use PDO;
use PDOException;
use Pulsar\Api\Internal;

use function preg_match;
use function sprintf;
use function time;

/**
 * PDO-backed session handler with concurrency control, listing, and revocation.
 *
 * Expected table schema (driver-agnostic DDL):
 *
 *     CREATE TABLE sessions (
 *         id VARCHAR(128) PRIMARY KEY,
 *         user_id VARCHAR(255) NULL,
 *         data TEXT NOT NULL,
 *         ip_address VARCHAR(45) NULL,
 *         user_agent TEXT NULL,
 *         last_activity INTEGER NOT NULL,
 *         created_at INTEGER NOT NULL
 *     );
 *     CREATE INDEX idx_sessions_user_id ON sessions (user_id);
 *     CREATE INDEX idx_sessions_last_activity ON sessions (last_activity);
 */
#[Internal]
final class DatabaseHandler implements SessionHandlerInterface
{
    private ?string $contextUserId = null;

    private string $contextIpAddress = '';

    private string $contextUserAgent = '';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tableName = 'sessions',
        private readonly int $lifetime = 7200,
    ) {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $tableName) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid session table name "%s": must be a valid SQL identifier (letters, digits, underscores).',
                $tableName,
            ));
        }
    }

    /**
     * Set session context metadata for the next write() call.
     *
     * Called by the session manager to associate user metadata with
     * the session row. This data is stored alongside session payload.
     */
    public function setSessionContext(
        ?string $userId,
        string $ipAddress,
        string $userAgent,
    ): void {
        $this->contextUserId = $userId;
        $this->contextIpAddress = $ipAddress;
        $this->contextUserAgent = $userAgent;
    }

    #[Override]
    public function open(string $path, string $name): bool
    {
        return true;
    }

    #[Override]
    public function close(): bool
    {
        return true;
    }

    #[Override]
    public function read(string $id): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT data FROM $this->tableName WHERE id = ?",
        );
        $stmt->execute([$id]);

        /** @var mixed $data */
        $data = $stmt->fetchColumn();

        return is_string($data) ? $data : '';
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        $now = time();

        try {
            $this->pdo->beginTransaction();

            $deleteStmt = $this->pdo->prepare(
                "DELETE FROM $this->tableName WHERE id = ?",
            );
            $deleteStmt->execute([$id]);

            $insertStmt = $this->pdo->prepare(
                "INSERT INTO $this->tableName (id, user_id, data, ip_address, user_agent, last_activity, created_at)"
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            );
            $insertStmt->execute([
                $id,
                $this->contextUserId,
                $data,
                $this->contextIpAddress,
                $this->contextUserAgent,
                $now,
                $now,
            ]);

            $this->pdo->commit();
        } catch (PDOException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return false;
        }

        return true;
    }

    #[Override]
    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM $this->tableName WHERE id = ?",
        );

        return $stmt->execute([$id]);
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        $threshold = time() - $max_lifetime;

        $stmt = $this->pdo->prepare(
            "DELETE FROM $this->tableName WHERE last_activity < ?",
        );
        $stmt->execute([$threshold]);

        return $stmt->rowCount();
    }

    #[Override]
    public function supportsConcurrencyControl(): bool
    {
        return true;
    }

    #[Override]
    public function supportsSessionListing(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRevocation(): bool
    {
        return true;
    }

    #[Override]
    public function getActiveSessions(string $userId): int
    {
        $threshold = time() - $this->lifetime;

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM $this->tableName WHERE user_id = ? AND last_activity >= ?",
        );
        $stmt->execute([$userId, $threshold]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{id: string, last_activity: int, ip_address: string, user_agent: string, created_at: int}>
     */
    #[Override]
    public function listSessions(string $userId): array
    {
        $threshold = time() - $this->lifetime;

        $stmt = $this->pdo->prepare(
            "SELECT id, last_activity, ip_address, user_agent, created_at FROM $this->tableName"
            . ' WHERE user_id = ? AND last_activity >= ? ORDER BY last_activity DESC',
        );
        $stmt->execute([$userId, $threshold]);

        /** @var list<array{id: string, last_activity: int|string, ip_address: string|null, user_agent: string|null, created_at: int|string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'id' => $row['id'],
                'last_activity' => (int) $row['last_activity'],
                'ip_address' => $row['ip_address'] ?? '',
                'user_agent' => $row['user_agent'] ?? '',
                'created_at' => (int) $row['created_at'],
            ];
        }

        return $result;
    }

    #[Override]
    public function revokeSession(string $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM $this->tableName WHERE id = ?",
        );
        $stmt->execute([$sessionId]);

        return $stmt->rowCount() > 0;
    }
}
