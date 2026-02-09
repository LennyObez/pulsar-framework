<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use Pulsar\Api\Api;

/**
 * Contract for log output destinations.
 */
#[Api(since: '1.0.0')]
interface LogSinkInterface
{
    /**
     * Write a log entry to this sink.
     */
    public function write(LogEntry $entry): void;
}
