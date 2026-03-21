<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function is_string;
use function time;

/**
 * Detects credential stuffing attacks.
 *
 * Tracks unique username attempts per IP per time window. Credential stuffing
 * differs from brute force: attackers try many different username/password
 * combinations (often from leaked databases) rather than targeting a single account.
 *
 * Compliance: DORA Art.17, NIS2 Art.21(b), PCI-DSS Req.11.
 * @api
 */
#[Api(since: '1.0.0')]
final class CredentialStuffingDetector implements ThreatDetectorInterface
{
    /** @var array<string, list<array{username: string, timestamp: int}>> IP => attempts */
    private array $attempts = [];

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

        $uniqueUsernames = $this->uniqueUsernamesForIp($ip);

        if ($uniqueUsernames < $this->threshold) {
            return null;
        }

        return ThreatEvent::create(
            category: ThreatCategory::CredentialStuffing,
            recommendedAction: ThreatResponse::Block,
            sourceIp: $ip,
            description: "Credential stuffing detected: {$uniqueUsernames} unique usernames attempted from {$ip} in {$this->windowSeconds}s",
            confidence: min(1.0, $uniqueUsernames / ($this->threshold * 2)),
            metadata: [
                'unique_usernames' => $uniqueUsernames,
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

        $ip = is_string($context['ip'] ?? null) ? $context['ip'] : '';
        $username = is_string($context['account'] ?? null) ? $context['account'] : '';

        if ($ip === '' || $username === '') {
            return;
        }

        $this->attempts[$ip][] = [
            'username' => $username,
            'timestamp' => time(),
        ];
    }

    private function uniqueUsernamesForIp(string $ip): int
    {
        $entries = $this->attempts[$ip] ?? [];
        $usernames = array_unique(
            array_map(static fn(array $e): string => $e['username'], $entries),
        );

        return count($usernames);
    }

    private function resolveIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        return is_string($params['REMOTE_ADDR'] ?? null) ? $params['REMOTE_ADDR'] : 'unknown';
    }

    private function pruneWindow(string $ip, int $now): void
    {
        $cutoff = $now - $this->windowSeconds;

        if (isset($this->attempts[$ip])) {
            $this->attempts[$ip] = array_values(
                array_filter(
                    $this->attempts[$ip],
                    static fn(array $e): bool => $e['timestamp'] >= $cutoff,
                ),
            );
        }
    }
}
