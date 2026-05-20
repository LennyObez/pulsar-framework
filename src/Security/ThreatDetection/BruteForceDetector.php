<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function array_filter;
use function count;
use function is_string;
use function time;

/**
 * Detects brute force authentication attacks.
 *
 * Tracks failed authentication attempts per IP and per account,
 * triggering progressive responses based on configurable thresholds.
 *
 * WARNING (multi-worker limitation): This detector uses in-memory storage
 * and is NOT shared across worker processes. In multi-worker deployments
 * (PHP-FPM, RoadRunner, Swoole), each worker maintains its own state.
 * An attacker distributing requests across N workers gets N * threshold
 * free attempts. For production multi-worker environments, use a
 * shared-storage implementation (Redis, database) instead.
 *
 * Compliance: DORA Art.17 (incident detection), NIS2 Art.21(b),
 * PCI-DSS Req.8.3.4 (account lockout).
 * @api
 */
#[Api(since: '1.0.0')]
final class BruteForceDetector implements ThreatDetectorInterface
{
    /** @var array<string, list<int>> IP => list of failure timestamps */
    private array $ipFailures = [];

    /** @var array<string, list<int>> account => list of failure timestamps */
    private array $accountFailures = [];

    public function __construct(
        private readonly int $threshold,
        private readonly int $windowSeconds,
    ) {}

    #[Override]
    public function analyze(ServerRequestInterface $request): ?ThreatEvent
    {
        $ip = $this->resolveIp($request);
        $now = time();
        $this->pruneWindow($ip, $now);

        $recentCount = count($this->ipFailures[$ip] ?? []);

        if ($recentCount < $this->threshold) {
            return null;
        }

        $action = match (true) {
            $recentCount >= $this->threshold * 3 => ThreatResponse::Block,
            $recentCount >= $this->threshold * 2 => ThreatResponse::Challenge,
            default => ThreatResponse::RateLimit,
        };

        return ThreatEvent::create(
            category: ThreatCategory::BruteForce,
            recommendedAction: $action,
            sourceIp: $ip,
            description: "Brute force detected: {$recentCount} failed attempts in {$this->windowSeconds}s from {$ip}",
            confidence: min(1.0, $recentCount / ($this->threshold * 3)),
            metadata: [
                'attempt_count' => $recentCount,
                'window_seconds' => $this->windowSeconds,
                'threshold' => $this->threshold,
            ],
        );
    }

    #[Override]
    public function recordEvent(string $eventType, array $context): void
    {
        if ($eventType !== 'auth.failure') {
            return;
        }

        /** @var mixed $rawIp */
        $rawIp = $context['ip'] ?? null;
        /** @var mixed $rawAccount */
        $rawAccount = $context['account'] ?? null;
        $ip = is_string($rawIp) ? $rawIp : '';
        $account = is_string($rawAccount) ? $rawAccount : '';
        $now = time();

        if ($ip !== '') {
            $this->ipFailures[$ip][] = $now;
        }

        if ($account !== '') {
            $this->accountFailures[$account][] = $now;
            $this->pruneAccountWindow($account, $now);
        }
    }

    /**
     * Get the number of recent failures for an account.
     */
    public function accountFailureCount(string $account): int
    {
        $cutoff = time() - $this->windowSeconds;
        $timestamps = $this->accountFailures[$account] ?? [];

        return count(array_filter($timestamps, static fn(int $t): bool => $t >= $cutoff));
    }

    private function resolveIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        return is_string($params['REMOTE_ADDR'] ?? null) ? $params['REMOTE_ADDR'] : 'unknown';
    }

    private function pruneWindow(string $ip, int $now): void
    {
        $cutoff = $now - $this->windowSeconds;

        if (isset($this->ipFailures[$ip])) {
            $this->ipFailures[$ip] = array_values(
                array_filter($this->ipFailures[$ip], static fn(int $t): bool => $t >= $cutoff),
            );
        }
    }

    private function pruneAccountWindow(string $account, int $now): void
    {
        $cutoff = $now - $this->windowSeconds;

        if (isset($this->accountFailures[$account])) {
            $this->accountFailures[$account] = array_values(
                array_filter($this->accountFailures[$account], static fn(int $t): bool => $t >= $cutoff),
            );
        }
    }
}
