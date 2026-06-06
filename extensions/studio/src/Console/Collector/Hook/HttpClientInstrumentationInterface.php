<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector\Hook;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\Payload\OutgoingHttpPayload;
use Pulsar\Observability\Context\CorrelationContext;

/**
 * Hook interface for outgoing HTTP client instrumentation.
 *
 * When the HTTP client subsystem is implemented, its decorator should
 * call record() for each outgoing request. Studio's collector
 * then forwards the event to storage.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
interface HttpClientInstrumentationInterface
{
    public function record(OutgoingHttpPayload $payload, ?CorrelationContext $context): void;
}
