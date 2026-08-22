<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Error;

use Pulsar\Api\Api;
use Pulsar\Observability\ErrorTracking\ErrorEvent;

/**
 * Contract for exporting error events to an external destination.
 * @api
 */
#[Api(since: '1.0.0')]
interface ErrorExporterInterface
{
    /**
     * Export a single error event.
     */
    public function export(ErrorEvent $event): void;

    /**
     * Flush any buffered events to the destination.
     */
    public function flush(): void;

    /**
     * Shut down the exporter, flushing remaining data and releasing resources.
     */
    public function shutdown(): void;
}
