<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\ExceptionPayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Throwable;

/**
 * Observes error events from ErrorAggregator and emits Studio exception events.
 *
 * Registered via ErrorAggregator::addObserver() during Studio::attach().
 */
#[Internal]
final class ExceptionCollector implements CollectorInterface
{
    public bool $enabled = true;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly CorrelationContextProviderInterface $contextProvider,
        private readonly Closure $emit,
    ) {}

    /**
     * Handle an error event from ErrorAggregator.
     *
     * This method is passed as a callable to ErrorAggregator::addObserver().
     */
    public function handleError(ErrorEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $payload = new ExceptionPayload(
            exceptionClass: $event->exceptionClass,
            message: $event->message,
            file: $event->file,
            line: $event->line,
            fingerprint: $event->fingerprint->value,
            stackTrace: $event->stackTrace,
        );

        $context = $this->contextProvider->current();

        try {
            ($this->emit)($payload, $context);
        } catch (Throwable) {
        }
    }

}
