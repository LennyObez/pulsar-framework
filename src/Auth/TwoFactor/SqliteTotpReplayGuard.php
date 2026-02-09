<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function hash;

use PDO;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Support\SqliteWalFactory;

use function random_int;

/**
 * TOTP replay guard backed by SQLite.
 *
 * Stores used TOTP codes in a shared SQLite database, providing
 * cross-process replay protection. Uses WAL mode for concurrent access
 * from multiple PHP-FPM workers.
 */
#[Internal]
final readonly class SqliteTotpReplayGuard implements TotpReplayGuardInterface
{
    private PDO $db;

    public function __construct(string $storagePath)
    {
        $this->db = SqliteWalFactory::create($storagePath, <<<'SQL'
            CREATE TABLE IF NOT EXISTS totp_used (
                identity_code TEXT PRIMARY KEY,
                used_at INTEGER NOT NULL
            )
            SQL);
    }

    public function markUsed(string $identityId, string $code, int $timestamp): bool
    {
        $key = $identityId . ':' . hash('sha256', $code);

        // Probabilistic pruning — entries older than 90 seconds
        if (random_int(1, 20) === 1) {
            $this->prune($timestamp - 90);
        }

        try {
            $this->db->prepare('INSERT INTO totp_used (identity_code, used_at) VALUES (?, ?)')
                ->execute([$key, $timestamp]);

            return true;
        } catch (PDOException) {
            // UNIQUE constraint violation — code already used
            return false;
        }
    }

    private function prune(int $before): void
    {
        try {
            $this->db->prepare('DELETE FROM totp_used WHERE used_at < ?')
                ->execute([$before]);
        } catch (PDOException) {
            // Best-effort pruning
        }
    }
}
