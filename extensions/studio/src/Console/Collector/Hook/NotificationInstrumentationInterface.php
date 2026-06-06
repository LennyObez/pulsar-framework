<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector\Hook;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\Payload\NotificationPayload;
use Pulsar\Observability\Context\CorrelationContext;

/**
 * Hook interface for notification/mail subsystem instrumentation.
 *
 * When the notification subsystem is implemented, its channel decorator
 * should call record() for each notification dispatch. Studio's collector
 * then forwards the event to storage.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
interface NotificationInstrumentationInterface
{
    public function record(NotificationPayload $payload, ?CorrelationContext $context): void;
}
