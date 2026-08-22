<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Override;
use Pulsar\Api\Api;

use function hrtime;
use function ltrim;
use function str_contains;
use function strtoupper;

/**
 * Routes SQL statements to read or write connections based on keyword analysis.
 *
 * Supports pinning all traffic to the primary after write operations,
 * with both request-scoped and timed stickiness modes.
 * @api
 */
#[Api(since: '1.0.0')]
final class ReadWriteRouter implements ReadWriteRouterInterface
{
    private bool $pinned = false;
    private ?int $pinExpiresAtNs = null;

    #[Override]
    public function route(string $sql): ConnectionRole
    {
        if ($this->isPinnedToPrimary()) {
            return ConnectionRole::Write;
        }

        $trimmed = ltrim($sql);
        $spacePos = strpos($trimmed, ' ');
        $keyword = $spacePos !== false ? strtoupper(substr($trimmed, 0, $spacePos)) : strtoupper($trimmed);

        // Handle parenthesized subqueries like "(SELECT ...)"
        if (str_contains($keyword, '(')) {
            $keyword = strtoupper(ltrim($keyword, '('));
        }

        return match ($keyword) {
            'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN' => ConnectionRole::Read,
            default => ConnectionRole::Write,
        };
    }

    #[Override]
    public function pinToPrimary(?int $durationMs = null): void
    {
        $this->pinned = true;

        if ($durationMs !== null) {
            $this->pinExpiresAtNs = hrtime(true) + ($durationMs * 1_000_000);
        } else {
            $this->pinExpiresAtNs = null;
        }
    }

    #[Override]
    public function isPinnedToPrimary(): bool
    {
        if (!$this->pinned) {
            return false;
        }

        if ($this->pinExpiresAtNs === null) {
            return true;
        }

        if (hrtime(true) >= $this->pinExpiresAtNs) {
            $this->pinned = false;
            $this->pinExpiresAtNs = null;

            return false;
        }

        return true;
    }

    #[Override]
    public function reset(): void
    {
        $this->pinned = false;
        $this->pinExpiresAtNs = null;
    }
}
