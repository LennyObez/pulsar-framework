<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Event;

use Closure;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Throwable;

use function hrtime;

/**
 * Bridges cache events to MetricRegistry counters and LoggerInterface.
 *
 * Metrics:
 * - pulsar_cache_hits_total (counter, labels: pool, driver)
 * - pulsar_cache_misses_total (counter, labels: pool, driver)
 * - pulsar_cache_writes_total (counter, labels: pool, driver)
 * - pulsar_cache_deletes_total (counter, labels: pool, driver)
 * - pulsar_cache_clears_total (counter, labels: pool, driver)
 * - pulsar_cache_errors_total (counter, labels: pool, driver)
 * - pulsar_cache_operation_duration_seconds (histogram, labels: pool, driver, operation)
 */
#[Internal]
final class CacheEventEmitter
{
    /** @var list<callable(CacheEvent): void> */
    private array $listeners = [];

    /** @var array<string, LabelSet> Pre-built label sets keyed by operation type */
    private array $labelCache = [];

    /** @var ?LabelSet Shared pool+driver label set for counters */
    private ?LabelSet $baseLabels = null;

    /** @var ?string Cached pool+driver identity for label cache keying */
    private ?string $lastPoolDriver = null;

    /** @var ?Closure(string): string */
    private ?Closure $keyHasher;

    public function __construct(
        private readonly ?MetricRegistry $metrics = null,
        private readonly ?LoggerInterface $logger = null,
        ?callable $keyHasher = null,
    ) {
        /** @var ?Closure(string): string $typedHasher */
        $typedHasher = $keyHasher !== null ? Closure::fromCallable($keyHasher) : null;
        $this->keyHasher = $typedHasher;
    }

    public function emit(CacheEvent $event): void
    {
        if ($this->keyHasher !== null && $event->hashedKey !== '') {
            $hashedKey = ($this->keyHasher)($event->hashedKey);
            $event = $this->withHashedKey($event, $hashedKey);
        }

        $this->recordMetrics($event);
        $this->logEvent($event);

        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable $e) {
                $this->logger?->error('Cache event listener threw an exception', [
                    'pool' => $event->poolName,
                    'driver' => $event->driverName,
                    'operation' => $event->operationType,
                    'exception' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);
            }
        }
    }

    /**
     * Convenience: emit a hit event.
     */
    public function emitHit(string $poolName, string $driverName, string $key, int $startNs): void
    {
        $this->emit(new CacheHitEvent(
            $poolName,
            $driverName,
            $key,
            (int) ((hrtime(true) - $startNs) / 1000),
        ));
    }

    /**
     * Convenience: emit a miss event.
     */
    public function emitMiss(string $poolName, string $driverName, string $key, int $startNs): void
    {
        $this->emit(new CacheMissEvent(
            $poolName,
            $driverName,
            $key,
            (int) ((hrtime(true) - $startNs) / 1000),
        ));
    }

    /**
     * Convenience: emit a write event.
     */
    public function emitWrite(string $poolName, string $driverName, string $key, int $startNs): void
    {
        $this->emit(new CacheWriteEvent(
            $poolName,
            $driverName,
            $key,
            (int) ((hrtime(true) - $startNs) / 1000),
        ));
    }

    /**
     * Convenience: emit a delete event.
     */
    public function emitDelete(string $poolName, string $driverName, string $key, int $startNs): void
    {
        $this->emit(new CacheDeleteEvent(
            $poolName,
            $driverName,
            $key,
            (int) ((hrtime(true) - $startNs) / 1000),
        ));
    }

    /**
     * Convenience: emit a clear event.
     */
    public function emitClear(string $poolName, string $driverName, int $startNs): void
    {
        $this->emit(new CacheClearEvent(
            $poolName,
            $driverName,
            (int) ((hrtime(true) - $startNs) / 1000),
        ));
    }

    /**
     * Convenience: emit an error event.
     */
    public function emitError(
        string $poolName,
        string $driverName,
        string $key,
        int $startNs,
        string $message,
        ?Throwable $exception = null,
    ): void {
        $this->emit(new CacheErrorEvent(
            $poolName,
            $driverName,
            $key,
            (int) ((hrtime(true) - $startNs) / 1000),
            $message,
            $exception,
        ));
    }

    /**
     * Register a listener for future event dispatcher integration.
     *
     * @param callable(CacheEvent): void $listener
     */
    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    private function recordMetrics(CacheEvent $event): void
    {
        if ($this->metrics === null) {
            return;
        }

        $labels = $this->getBaseLabels($event->poolName, $event->driverName);

        match ($event->operationType) {
            'hit' => $this->metrics->counter('pulsar_cache_hits_total', 'Cache hit count')->increment($labels),
            'miss' => $this->metrics->counter('pulsar_cache_misses_total', 'Cache miss count')->increment($labels),
            'write' => $this->metrics->counter('pulsar_cache_writes_total', 'Cache write count')->increment($labels),
            'delete' => $this->metrics->counter('pulsar_cache_deletes_total', 'Cache delete count')->increment($labels),
            'clear' => $this->metrics->counter('pulsar_cache_clears_total', 'Cache clear count')->increment($labels),
            'error' => $this->metrics->counter('pulsar_cache_errors_total', 'Cache error count')->increment($labels),
            default => null,
        };

        $operationLabels = $this->getOperationLabels($event->poolName, $event->driverName, $event->operationType);

        $this->metrics->histogram(
            'pulsar_cache_operation_duration_seconds',
            'Cache operation duration in seconds',
            [0.0001, 0.0005, 0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1.0],
        )->observe($event->durationMicroseconds / 1_000_000, $operationLabels);
    }

    private function logEvent(CacheEvent $event): void
    {
        if ($this->logger === null) {
            return;
        }

        if ($event instanceof CacheErrorEvent) {
            $this->logger->warning('Cache error: {message}', [
                'pool' => $event->poolName,
                'driver' => $event->driverName,
                'key' => $event->hashedKey,
                'message' => $event->errorMessage,
                'error_class' => $event->errorClass,
                'duration_us' => $event->durationMicroseconds,
            ]);

            return;
        }

        $this->logger->debug('Cache {operation} on {pool}', [
            'pool' => $event->poolName,
            'driver' => $event->driverName,
            'key' => $event->hashedKey,
            'operation' => $event->operationType,
            'duration_us' => $event->durationMicroseconds,
        ]);
    }

    private function getBaseLabels(string $poolName, string $driverName): LabelSet
    {
        $identity = $poolName . '|' . $driverName;

        if ($this->lastPoolDriver !== $identity) {
            $this->baseLabels = null;
            $this->labelCache = [];
            $this->lastPoolDriver = $identity;
        }

        return $this->baseLabels ??= new LabelSet(['pool' => $poolName, 'driver' => $driverName]);
    }

    private function getOperationLabels(string $poolName, string $driverName, string $operation): LabelSet
    {
        $this->getBaseLabels($poolName, $driverName);

        return $this->labelCache[$operation] ??= new LabelSet([
            'pool' => $poolName,
            'driver' => $driverName,
            'operation' => $operation,
        ]);
    }

    private function withHashedKey(CacheEvent $event, string $hashedKey): CacheEvent
    {
        if ($event instanceof CacheErrorEvent) {
            return new CacheErrorEvent(
                $event->poolName,
                $event->driverName,
                $hashedKey,
                $event->durationMicroseconds,
                $event->errorMessage,
            );
        }

        if ($event instanceof CacheClearEvent) {
            return $event;
        }

        return match ($event->operationType) {
            'hit' => new CacheHitEvent($event->poolName, $event->driverName, $hashedKey, $event->durationMicroseconds),
            'miss' => new CacheMissEvent($event->poolName, $event->driverName, $hashedKey, $event->durationMicroseconds),
            'write' => new CacheWriteEvent($event->poolName, $event->driverName, $hashedKey, $event->durationMicroseconds),
            'delete' => new CacheDeleteEvent($event->poolName, $event->driverName, $hashedKey, $event->durationMicroseconds),
            default => $event,
        };
    }
}
