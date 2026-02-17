<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Noop;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogSinkInterface;

/**
 * No-operation log sink that silently discards all log entries.
 */
#[Api(since: '1.0.0')]
final readonly class NoopLogSink implements LogSinkInterface
{
    #[Override]
    public function write(LogEntry $entry): void
    {
        // Intentionally empty.
    }
}
