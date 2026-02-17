<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Throwable;

use function count;
use function microtime;
use function number_format;
use function sprintf;

/**
 * Pre-establishes database and cache connections during worker boot.
 *
 * In persistent runtimes, cold connections on first request add latency.
 * ConnectionWarmup runs during the boot phase to establish connections
 * before any requests arrive, ensuring consistent first-request latency.
 */
#[Api(since: '1.0.0')]
final class ConnectionWarmup
{
    /**
     * @var list<ConnectionWarmable>
     */
    private array $warmables = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Register a warmable connection.
     */
    public function register(ConnectionWarmable $warmable): void
    {
        $this->warmables[] = $warmable;
    }

    /**
     * Warm all registered connections.
     *
     * @return ConnectionWarmupResult Results of the warmup attempt
     */
    public function warmAll(): ConnectionWarmupResult
    {
        $startTime = microtime(true);
        $succeeded = 0;
        $failed = 0;
        /** @var list<string> $errors */
        $errors = [];

        foreach ($this->warmables as $warmable) {
            try {
                $warmable->warmConnection();
                $succeeded++;

                $this->logger?->debug(sprintf(
                    'Connection warmed: %s',
                    $warmable->connectionName(),
                ));
            } catch (Throwable $e) {
                $failed++;
                $errors[] = sprintf('%s: %s', $warmable->connectionName(), $e->getMessage());

                $this->logger?->warning(sprintf(
                    'Connection warmup failed: %s: %s',
                    $warmable->connectionName(),
                    $e->getMessage(),
                ));
            }
        }

        $elapsedMs = (microtime(true) - $startTime) * 1000.0;

        $this->logger?->info(sprintf(
            'Connection warmup complete: %d/%d succeeded in %sms',
            $succeeded,
            count($this->warmables),
            number_format($elapsedMs, 2),
        ));

        return new ConnectionWarmupResult(
            total: count($this->warmables),
            succeeded: $succeeded,
            failed: $failed,
            errors: $errors,
            elapsedMs: $elapsedMs,
        );
    }

    /**
     * Get the number of registered warmable connections.
     */
    public function count(): int
    {
        return count($this->warmables);
    }
}
