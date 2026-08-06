<?php

declare(strict_types=1);

namespace Pulsar\Auth\Internal\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\TwoFactor\TotpReplayGuardInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;

use function date;
use function random_int;

/**
 * Database-backed TOTP replay guard for production multi-worker deployments.
 *
 * Stores used TOTP time steps in the `auth_totp_replay_guard` table keyed by
 * (user_id, time_step), providing cross-process replay protection.
 *
 * Retention covers the verifier's whole acceptance envelope --
 * (2 * verificationWindow + 1) periods -- plus one period of clock-skew margin,
 * so an entry is never forgotten while the code it blocks is still accepted.
 *
 * Probabilistic pruning keeps the table bounded and is scoped to the submitting
 * user, so unrelated traffic cannot evict another user's blocking record. Rows
 * belonging to identities that never authenticate again are left behind; that is
 * at most a handful per dormant account, and is the price of not letting one
 * tenant's request touch another tenant's guard state.
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
     * Default number of adjacent time steps the verifier accepts on either side.
     */
    private const int DEFAULT_VERIFICATION_WINDOW = 1;

    /**
     * Extra periods of retention beyond the acceptance envelope, to absorb clock
     * skew between the worker that recorded the entry and the one that expires it.
     */
    private const int DRIFT_PERIODS = 1;

    /**
     * Probabilistic pruning threshold: prune on 1-in-N calls.
     */
    private const int PRUNE_PROBABILITY = 20;

    private int $retention;

    public function __construct(
        private ConnectionInterface $connection,
        int $period = self::DEFAULT_PERIOD,
        int $verificationWindow = self::DEFAULT_VERIFICATION_WINDOW,
    ) {
        $this->retention = (2 * $verificationWindow + 1 + self::DRIFT_PERIODS) * $period;
    }

    #[Override]
    public function markUsed(string $identityId, int $timeStep, int $timestamp): bool
    {
        // Probabilistic pruning to prevent unbounded table growth
        if (random_int(1, self::PRUNE_PROBABILITY) === 1) {
            $this->prune($identityId, $timestamp);
        }

        // Attempt INSERT: primary key constraint prevents duplicates
        try {
            $this->connection->execute(
                <<<'SQL'
                    INSERT INTO auth_totp_replay_guard (user_id, time_step, used_at)
                    VALUES (:user_id, :time_step, :used_at)
                    SQL,
                [
                    ':user_id' => $identityId,
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
     * Remove this user's entries recorded before the retention cutoff.
     */
    private function prune(string $identityId, int $currentTimestamp): void
    {
        $cutoff = date('Y-m-d H:i:s', $currentTimestamp - $this->retention);

        try {
            $this->connection->execute(
                'DELETE FROM auth_totp_replay_guard WHERE user_id = :user_id AND used_at < :cutoff',
                [':user_id' => $identityId, ':cutoff' => $cutoff],
            );
        } catch (DatabaseException) {
            // Best-effort pruning: do not fail the guard operation
        }
    }
}
