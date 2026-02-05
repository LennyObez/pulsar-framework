<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Collector\Hook;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\Payload\NotificationPayload;
use Pulsar\Studio\CorrelationContext;

/**
 * Hook interface for notification/mail subsystem instrumentation.
 *
 * When the notification subsystem is implemented, its channel decorator
 * should call record() for each notification dispatch. Studio's collector
 * then forwards the event to storage.
 */
#[Internal]
interface NotificationInstrumentationInterface
{
    public function record(NotificationPayload $payload, ?CorrelationContext $context): void;
}
