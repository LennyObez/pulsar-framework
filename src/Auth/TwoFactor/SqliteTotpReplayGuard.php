<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Override;
use PDO;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Support\SqliteWalFactory;

use function random_int;

/**
 * TOTP replay guard backed by SQLite.
 *
 * Stores used TOTP time steps in a shared SQLite database keyed by
 * (identity_id, time_step), providing cross-process replay protection.
 * Uses WAL mode for concurrent access from multiple PHP-FPM workers.
 *
 * Pruning is scoped to the submitting identity, so no amount of unrelated
 * traffic can evict another identity's blocking record.
 */
#[Internal]
final readonly class SqliteTotpReplayGuard implements TotpReplayGuardInterface
{
    /**
     * Extra periods of retention beyond the acceptance envelope, to absorb
     * clock skew between the worker that recorded the entry and the one that
     * expires it.
     */
    private const int DRIFT_PERIODS = 1;

    /**
     * Probabilistic pruning threshold: prune on 1-in-N calls.
     */
    private const int PRUNE_PROBABILITY = 20;

    private PDO $db;

    private int $retention;

    /**
     * @param string $storagePath Path to the SQLite database file
     * @param int $codePeriod TOTP time step length in seconds
     * @param int $verificationWindow Adjacent time steps the verifier accepts on either side
     */
    public function __construct(string $storagePath, int $codePeriod = 30, int $verificationWindow = 1)
    {
        // `totp_used` was keyed on purpose, which let one code be redeemed once
        // per purpose. Entries live at most one acceptance envelope, so dropping
        // the legacy table costs nothing durable.
        $this->db = SqliteWalFactory::create($storagePath, <<<'SQL'
            DROP TABLE IF EXISTS totp_used;
            CREATE TABLE IF NOT EXISTS totp_replay (
                identity_id TEXT NOT NULL,
                time_step INTEGER NOT NULL,
                used_at INTEGER NOT NULL,
                PRIMARY KEY (identity_id, time_step)
            );
            SQL);

        $this->retention = (2 * $verificationWindow + 1 + self::DRIFT_PERIODS) * $codePeriod;
    }

    #[Override]
    public function markUsed(string $identityId, int $timeStep, int $timestamp): bool
    {
        if (random_int(1, self::PRUNE_PROBABILITY) === 1) {
            $this->prune($identityId, $timestamp - $this->retention);
        }

        try {
            $this->db->prepare(
                'INSERT INTO totp_replay (identity_id, time_step, used_at) VALUES (?, ?, ?)',
            )->execute([$identityId, $timeStep, $timestamp]);

            return true;
        } catch (PDOException) {
            // PRIMARY KEY constraint violation: time step already used
            return false;
        }
    }

    /**
     * Remove this identity's entries recorded before the retention cutoff.
     */
    private function prune(string $identityId, int $before): void
    {
        try {
            $this->db->prepare('DELETE FROM totp_replay WHERE identity_id = ? AND used_at < ?')
                ->execute([$identityId, $before]);
        } catch (PDOException) {
            // Best-effort pruning
        }
    }
}
