<?php

declare(strict_types=1);

namespace Pulsar\Auth\Internal\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\TwoFactor\TotpReplayGuardInterface;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;

use function date;
use function random_int;

/**
 * Database-backed TOTP replay guard for production multi-worker deployments.
 *
 * Stores used TOTP time steps in the `auth_totp_replay_guard` table keyed by
 * (user_id, purpose, time_step), providing cross-process replay protection.
 *
 * Probabilistic pruning removes entries older than 2 TOTP periods (default 60s)
 * to prevent unbounded table growth.
 *
 * Uses parameterized queries exclusively to prevent SQL injection (CWE-89).
 */
#[Internal]
final readonly class DatabaseTotpReplayGuard implements TotpReplayGuardInterface
{
    /**
     * Default TOTP period in seconds.
     */
    private const int DEFAULT_PERIOD = 30;

    /**
     * TTL multiplier: entries older than (period * TTL_PERIODS) are pruned.
     */
    private const int TTL_PERIODS = 2;

    /**
     * Probabilistic pruning threshold: prune on 1-in-N calls.
     */
    private const int PRUNE_PROBABILITY = 20;

    private int $ttl;

    public function __construct(
        private ConnectionInterface $connection,
        int $period = self::DEFAULT_PERIOD,
    ) {
        $this->ttl = $period * self::TTL_PERIODS;
    }

    #[Override]
    public function markUsed(string $identityId, TwoFactorPurpose $purpose, int $timeStep, int $timestamp): bool
    {
        // Probabilistic pruning to prevent unbounded table growth
        if (random_int(1, self::PRUNE_PROBABILITY) === 1) {
            $this->prune($timestamp);
        }

        // Attempt INSERT: primary key constraint prevents duplicates
        try {
            $this->connection->execute(
                <<<'SQL'
                    INSERT INTO auth_totp_replay_guard (user_id, purpose, time_step, used_at)
                    VALUES (:user_id, :purpose, :time_step, :used_at)
                    SQL,
                [
                    ':user_id' => $identityId,
                    ':purpose' => $purpose->value,
                    ':time_step' => $timeStep,
                    ':used_at' => date('Y-m-d H:i:s', $timestamp),
                ],
            );

            return true;
        } catch (DatabaseException) {
            // Primary key constraint violation: time step already used
            return false;
        }
    }

    /**
     * Remove entries older than the TTL window.
     */
    private function prune(int $currentTimestamp): void
    {
        $cutoff = date('Y-m-d H:i:s', $currentTimestamp - $this->ttl);

        try {
            $this->connection->execute(
                'DELETE FROM auth_totp_replay_guard WHERE used_at < :cutoff',
                [':cutoff' => $cutoff],
            );
        } catch (DatabaseException) {
            // Best-effort pruning: do not fail the guard operation
        }
    }
}
