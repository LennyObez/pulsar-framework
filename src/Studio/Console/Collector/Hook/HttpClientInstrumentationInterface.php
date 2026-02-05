<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Collector\Hook;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\Payload\OutgoingHttpPayload;
use Pulsar\Studio\CorrelationContext;

/**
 * Hook interface for outgoing HTTP client instrumentation.
 *
 * When the HTTP client subsystem is implemented, its decorator should
 * call record() for each outgoing request. Studio's collector
 * then forwards the event to storage.
 */
#[Internal]
interface HttpClientInstrumentationInterface
{
    public function record(OutgoingHttpPayload $payload, ?CorrelationContext $context): void;
}
