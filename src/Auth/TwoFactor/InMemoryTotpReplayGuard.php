<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function array_filter;
use function hash;

use Pulsar\Api\Internal;

/**
 * In-memory TOTP replay guard for single-process and testing use.
 *
 * Tracks used codes in process memory. Does not protect against replay
 * attacks across different processes or workers — use SqliteTotpReplayGuard
 * for production multi-worker deployments.
 */
#[Internal]
final class InMemoryTotpReplayGuard implements TotpReplayGuardInterface
{
    /**
     * @var array<string, array<string, int>> identity => [codeHash => timestamp]
     */
    private array $used = [];

    public function markUsed(string $identityId, string $code, int $timestamp): bool
    {
        $codeHash = hash('sha256', $code);

        // Prune expired entries for this identity (older than 90 seconds)
        if (isset($this->used[$identityId])) {
            $this->used[$identityId] = array_filter(
                $this->used[$identityId],
                static fn(int $ts): bool => $ts > $timestamp - 90,
            );
        }

        if (isset($this->used[$identityId][$codeHash])) {
            return false;
        }

        $this->used[$identityId][$codeHash] = $timestamp;

        return true;
    }
}
