<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use PDO;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Support\SqliteWalFactory;

use function random_int;

/**
 * TOTP replay guard backed by SQLite.
 *
 * Stores used TOTP time steps in a shared SQLite database keyed by
 * (identity_id, purpose, time_step), providing cross-process replay
 * protection. Uses WAL mode for concurrent access from multiple PHP-FPM workers.
 */
#[Internal]
final readonly class SqliteTotpReplayGuard implements TotpReplayGuardInterface
{
    private PDO $db;

    public function __construct(string $storagePath)
    {
        $this->db = SqliteWalFactory::create($storagePath, <<<'SQL'
            CREATE TABLE IF NOT EXISTS totp_used (
                identity_id TEXT NOT NULL,
                purpose TEXT NOT NULL,
                time_step INTEGER NOT NULL,
                used_at INTEGER NOT NULL,
                PRIMARY KEY (identity_id, purpose, time_step)
            )
            SQL);
    }

    public function markUsed(string $identityId, TwoFactorPurpose $purpose, int $timeStep, int $timestamp): bool
    {
        // Probabilistic pruning — entries older than 90 seconds
        if (random_int(1, 20) === 1) {
            $this->prune($timestamp - 90);
        }

        try {
            $this->db->prepare(
                'INSERT INTO totp_used (identity_id, purpose, time_step, used_at) VALUES (?, ?, ?, ?)',
            )->execute([$identityId, $purpose->value, $timeStep, $timestamp]);

            return true;
        } catch (PDOException) {
            // PRIMARY KEY constraint violation — time step already used
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
