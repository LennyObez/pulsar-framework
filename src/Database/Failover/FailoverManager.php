<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Closure;
use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Health\ConnectionHealthCheckerInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\CircuitBreakerState;

use function microtime;
use function sprintf;
use function uniqid;

/**
 * Manages database failover detection and endpoint switching.
 *
 * Integrates with circuit breaking to prevent cascading failures, emits
 * telemetry counters via MetricRegistry, and produces compliance-grade
 * FailoverEvent records for regulated environments.
 */
#[Api(since: '1.0.0')]
final class FailoverManager implements FailoverManagerInterface
{
    private string $currentPrimary;

    /** @var list<FailoverEvent> */
    private array $events = [];

    /** @var (Closure(): ConnectionInterface)|null */
    private ?Closure $connectionFactory;

    /**
     * @param (callable(): ConnectionInterface)|null $connectionFactory
     */
    public function __construct(
        private ConnectionInterface $primaryConnection,
        private readonly ConnectionHealthCheckerInterface $healthChecker,
        private readonly FailoverStrategyInterface $strategy,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly FailoverConfig $config,
        private readonly MetricRegistry $metrics,
        string $primaryEndpoint,
        ?callable $connectionFactory = null,
    ) {
        $this->currentPrimary = $primaryEndpoint;
        $this->connectionFactory = $connectionFactory !== null
            ? $connectionFactory(...)
            : null;
    }

    #[Override]
    public function checkPrimary(): bool
    {
        $healthy = $this->healthChecker->isHealthy($this->primaryConnection);

        if ($healthy) {
            $this->circuitBreaker->recordSuccess();
        } else {
            $this->circuitBreaker->recordFailure();
            $this->metrics->counter('db.primary_unavailable', 'Primary health check failures')->increment();
        }

        return $healthy;
    }

    #[Override]
    public function executeFailover(): bool
    {
        $start = microtime(true);
        $sourceEndpoint = $this->currentPrimary;

        $target = $this->strategy->resolveTarget();

        if ($target === null) {
            $this->metrics->counter('db.failover_executed', 'Failover attempts')->increment();

            throw DatabaseException::failoverFailed(
                sprintf('Strategy "%s" could not resolve a failover target', $this->strategy->name()),
            );
        }

        $this->currentPrimary = $target;

        // Reconnect to the new primary if a connection factory is available
        if ($this->connectionFactory !== null) {
            $this->primaryConnection->disconnect();
            $this->primaryConnection = ($this->connectionFactory)();
        }

        $this->circuitBreaker->reset();

        $durationMs = (microtime(true) - $start) * 1000.0;

        $this->metrics->counter('db.failover_executed', 'Failover executions')->increment();

        if ($this->config->complianceEventsEnabled) {
            $event = new FailoverEvent(
                reason: 'Primary health check failure',
                sourceEndpoint: $sourceEndpoint,
                targetEndpoint: $target,
                affectedOperationCount: 0,
                durationMs: $durationMs,
                correlationId: uniqid('fo_', true),
                occurredAt: new DateTimeImmutable(),
            );
            $this->events[] = $event;
        }

        return true;
    }

    #[Override]
    public function isCircuitOpen(): bool
    {
        return $this->circuitBreaker->state() === CircuitBreakerState::Open;
    }

    #[Override]
    public function getCurrentPrimary(): string
    {
        return $this->currentPrimary;
    }

    /**
     * Get the current primary connection.
     *
     * After a failover with a connection factory, this returns the
     * newly created connection to the failover target.
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->primaryConnection;
    }

    /**
     * Get all recorded failover events (compliance-grade audit trail).
     *
     * @return list<FailoverEvent>
     */
    public function events(): array
    {
        return $this->events;
    }
}
