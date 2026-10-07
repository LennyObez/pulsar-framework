<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogSinkInterface;
use Throwable;

use function sprintf;

/**
 * Log sink that forwards log entries to Studio as events.
 *
 * Reads the current correlation context from the provider
 * to associate log entries with their originating request or job.
 */
#[Internal]
final class LogCollector implements LogSinkInterface, CollectorInterface
{
    public bool $enabled = true;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly CorrelationContextProviderInterface $contextProvider,
        private readonly Closure $emit,
    ) {}

    #[Override]
    public function write(LogEntry $entry): void
    {
        if (!$this->enabled) {
            return;
        }

        $event = new LogEntryPayload(
            level: $entry->level->value,
            message: $entry->message,
            channel: $entry->channel,
            context: $entry->context,
        );

        $context = $this->contextProvider->current();

        try {
            ($this->emit)($event, $context);
        } catch (Throwable $e) {
            // Studio collectors must never crash the host application:
            // log emission is best-effort. Tracking the failure on stderr
            // surfaces collector regressions during dev/CI without
            // interrupting the user-facing request that triggered the log.
            fwrite(STDERR, sprintf(
                "[studio.LogCollector] failed to emit log event: %s\n",
                $e->getMessage(),
            ));
        }
    }
}
