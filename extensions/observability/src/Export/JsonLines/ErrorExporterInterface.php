<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\JsonLines;

use Pulsar\Api\Api;
use Pulsar\Observability\ErrorTracking\ErrorEvent;

/**
 * Contract for exporting error events to an external destination.
 */
#[Api(since: '1.0.0')]
interface ErrorExporterInterface
{
    public function export(ErrorEvent $event): void;

    public function flush(): void;

    public function shutdown(): void;
}
