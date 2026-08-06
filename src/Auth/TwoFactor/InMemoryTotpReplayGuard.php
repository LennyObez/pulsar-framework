<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Override;
use Pulsar\Api\Internal;

use function array_filter;

/**
 * In-memory TOTP replay guard for single-process and testing use.
 *
 * Tracks used time steps in process memory keyed by (identityId, timeStep).
 * Does not protect against replay attacks across different processes or workers --
 * use SqliteTotpReplayGuard for production multi-worker deployments.
 */
#[Internal]
final class InMemoryTotpReplayGuard implements TotpReplayGuardInterface
{
    /**
     * Extra periods of retention beyond the acceptance envelope, to absorb
     * clock skew between the node that recorded the entry and the node that
     * expires it.
     */
    private const int DRIFT_PERIODS = 1;

    /**
     * @var array<string, int> compositeKey => timestamp
     */
    private array $used = [];

    private readonly int $retention;

    /**
     * @param int $codePeriod TOTP time step length in seconds
     * @param int $verificationWindow Adjacent time steps the verifier accepts on either side
     */
    public function __construct(int $codePeriod = 30, int $verificationWindow = 1)
    {
        $this->retention = (2 * $verificationWindow + 1 + self::DRIFT_PERIODS) * $codePeriod;
    }

    #[Override]
    public function markUsed(string $identityId, int $timeStep, int $timestamp): bool
    {
        $key = $identityId . ':' . $timeStep;

        // Expiry is purely time-based and $timestamp is the verifier's clock,
        // never attacker input, so sweeping every identity at once is safe and
        // keeps a long-lived worker's memory bounded by live traffic.
        $this->used = array_filter(
            $this->used,
            fn(int $ts): bool => $ts > $timestamp - $this->retention,
        );

        if (isset($this->used[$key])) {
            return false;
        }

        $this->used[$key] = $timestamp;

        return true;
    }
}
