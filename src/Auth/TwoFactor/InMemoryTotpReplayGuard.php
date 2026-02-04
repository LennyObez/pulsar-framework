<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function array_filter;

use Pulsar\Api\Internal;

/**
 * In-memory TOTP replay guard for single-process and testing use.
 *
 * Tracks used time steps in process memory keyed by (identityId, purpose, timeStep).
 * Does not protect against replay attacks across different processes or workers —
 * use SqliteTotpReplayGuard for production multi-worker deployments.
 */
#[Internal]
final class InMemoryTotpReplayGuard implements TotpReplayGuardInterface
{
    /**
     * @var array<string, int> compositeKey => timestamp
     */
    private array $used = [];

    private int $ttl;

    public function __construct(int $ttl = 90)
    {
        $this->ttl = $ttl;
    }

    public function markUsed(string $identityId, TwoFactorPurpose $purpose, int $timeStep, int $timestamp): bool
    {
        $key = $identityId . ':' . $purpose->value . ':' . $timeStep;

        // Prune expired entries
        $this->used = array_filter(
            $this->used,
            fn(int $ts): bool => $ts > $timestamp - $this->ttl,
        );

        if (isset($this->used[$key])) {
            return false;
        }

        $this->used[$key] = $timestamp;

        return true;
    }
}
