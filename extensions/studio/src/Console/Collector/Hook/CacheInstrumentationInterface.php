<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector\Hook;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\Payload\CacheOperationPayload;
use Pulsar\Observability\Context\CorrelationContext;

/**
 * Hook interface for cache subsystem instrumentation.
 *
 * When the cache subsystem is implemented, its decorator should call
 * record() for each operation. Studio's collector then forwards
 * the event to storage.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
interface CacheInstrumentationInterface
{
    public function record(CacheOperationPayload $payload, ?CorrelationContext $context): void;
}
