<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\FeatureFlagPayload;
use Pulsar\FeatureFlag\FlagEvaluation;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Throwable;

/**
 * Observes feature flag evaluations and emits Studio events.
 *
 * Registered via FlagEvaluationLog::addObserver() during Studio::attach().
 */
#[Internal]
final class FeatureFlagCollector implements CollectorInterface
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
     * Handle a flag evaluation from FlagEvaluationLog.
     *
     * This method is passed as a callable to FlagEvaluationLog::addObserver().
     */
    public function handleEvaluation(FlagEvaluation $evaluation): void
    {
        if (!$this->enabled) {
            return;
        }

        $payload = new FeatureFlagPayload(
            flagName: $evaluation->flagName,
            result: $evaluation->result,
            reason: $evaluation->reason->value,
            contextIdentifier: $evaluation->context->userId ?? $evaluation->context->tenantId,
        );

        $context = $this->contextProvider->current();

        try {
            ($this->emit)($payload, $context);
        } catch (Throwable) {
        }
    }

}
