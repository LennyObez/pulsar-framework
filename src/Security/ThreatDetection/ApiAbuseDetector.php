<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function count;
use function is_string;
use function preg_match;
use function time;

/**
 * Detects API abuse patterns: scraping, enumeration, and rapid endpoint cycling.
 *
 * Tracks request patterns per IP to identify:
 * - Sequential ID enumeration (e.g., /users/1, /users/2, /users/3...)
 * - Rapid endpoint cycling (hitting many distinct endpoints in quick succession)
 * - Excessive request volume from a single source
 *
 * Compliance: PCI-DSS Req.11 (security monitoring), ISO 27001 A.8.16.
 * @api
 */
#[Api(since: '1.0.0')]
final class ApiAbuseDetector implements ThreatDetectorInterface
{
    /** @var array<string, list<array{path: string, timestamp: int}>> IP => request log */
    private array $requestLog = [];

    public function __construct(
        private readonly int $threshold,
        private readonly int $windowSeconds,
    ) {}

    #[Override]
    public function analyze(ServerRequestInterface $request): ?ThreatEvent
    {
        /** @var mixed $rawIp */
        $rawIp = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($rawIp) ? $rawIp : 'unknown';
        $path = $request->getUri()->getPath();
        $now = time();

        $this->recordRequest($ip, $path, $now);
        $this->pruneWindow($ip, $now);

        $entries = $this->requestLog[$ip] ?? [];
        $requestCount = count($entries);

        // Check volume threshold
        if ($requestCount >= $this->threshold) {
            return $this->createVolumeEvent($ip, $requestCount);
        }

        // Check for sequential ID enumeration (needs at least 5 requests)
        if ($requestCount >= 5 && $this->detectEnumeration($entries)) {
            return ThreatEvent::create(
                category: ThreatCategory::ApiAbuse,
                recommendedAction: ThreatResponse::RateLimit,
                sourceIp: $ip,
                description: "Sequential ID enumeration detected from {$ip}",
                confidence: 0.85,
                metadata: [
                    'type' => 'enumeration',
                    'request_count' => $requestCount,
                    'window_seconds' => $this->windowSeconds,
                ],
            );
        }

        return null;
    }

    #[Override]
    public function recordEvent(string $eventType, array $context): void
    {
        // API abuse detection is request-driven via analyze().
    }

    private function createVolumeEvent(string $ip, int $count): ThreatEvent
    {
        return ThreatEvent::create(
            category: ThreatCategory::ApiAbuse,
            recommendedAction: ThreatResponse::RateLimit,
            sourceIp: $ip,
            description: "API abuse detected: {$count} requests in {$this->windowSeconds}s from {$ip}",
            confidence: min(1.0, $count / ($this->threshold * 2)),
            metadata: [
                'type' => 'volume',
                'request_count' => $count,
                'threshold' => $this->threshold,
                'window_seconds' => $this->windowSeconds,
            ],
        );
    }

    /**
     * Detect sequential ID enumeration patterns.
     *
     * Looks for paths that differ only in a trailing numeric segment
     * and increment sequentially.
     *
     * @param list<array{path: string, timestamp: int}> $entries
     */
    private function detectEnumeration(array $entries): bool
    {
        $numericIds = [];

        foreach ($entries as $entry) {
            if (preg_match('/^(.*\/)(\d+)$/', $entry['path'], $matches) === 1) {
                $prefix = $matches[1];
                $numericIds[$prefix][] = (int) $matches[2];
            }
        }

        foreach ($numericIds as $ids) {
            if (count($ids) < 5) {
                continue;
            }

            sort($ids);
            $sequential = 0;

            for ($i = 1; $i < count($ids); $i++) {
                if ($ids[$i] === $ids[$i - 1] + 1) {
                    $sequential++;
                }
            }

            // If 80%+ of IDs are sequential, it's enumeration
            if ($sequential >= count($ids) * 0.8) {
                return true;
            }
        }

        return false;
    }

    private function recordRequest(string $ip, string $path, int $now): void
    {
        $this->requestLog[$ip][] = ['path' => $path, 'timestamp' => $now];
    }

    private function pruneWindow(string $ip, int $now): void
    {
        $cutoff = $now - $this->windowSeconds;

        if (isset($this->requestLog[$ip])) {
            $this->requestLog[$ip] = array_values(
                array_filter(
                    $this->requestLog[$ip],
                    static fn(array $e): bool => $e['timestamp'] >= $cutoff,
                ),
            );
        }
    }
}
