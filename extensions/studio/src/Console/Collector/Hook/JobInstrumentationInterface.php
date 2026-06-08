<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector\Hook;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\Payload\JobPayload;
use Pulsar\Observability\Context\CorrelationContext;

/**
 * Hook interface for async queue/job subsystem instrumentation.
 *
 * When the queue subsystem is implemented, its worker decorator should
 * call record() for each job state transition. Studio's collector
 * then forwards the event to storage.
 */
#[Internal]
interface JobInstrumentationInterface
{
    public function record(JobPayload $payload, ?CorrelationContext $context): void;
}
