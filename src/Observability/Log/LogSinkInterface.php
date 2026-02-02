<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

/**
 * Contract for log output destinations.
 */
interface LogSinkInterface
{
    /**
     * Write a log entry to this sink.
     */
    public function write(LogEntry $entry): void;
}
