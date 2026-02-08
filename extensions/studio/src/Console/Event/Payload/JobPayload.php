<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Queue job event payload.
 *
 * Prepared for future queue subsystem. Active collector will be wired
 * when src/Queue/ is implemented via JobInstrumentationInterface.
 */
#[Internal]
final readonly class JobPayload implements ConsoleEvent
{
    public function __construct(
        public string $jobClass,
        public string $status,
        public ?string $queue = null,
        public ?float $durationMs = null,
        public ?string $errorMessage = null,
        public int $attempts = 1,
        public ?string $connection = null,
    ) {}

    public function eventType(): EventType
    {
        $type = $this->status;

        return match ($type) {
            'queued' => EventType::JobQueued,
            'processing' => EventType::JobProcessing,
            'completed' => EventType::JobCompleted,
            default => EventType::JobFailed,
        };
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'job_class' => $this->jobClass,
            'status' => $this->status,
            'queue' => $this->queue,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
            'attempts' => $this->attempts,
            'connection' => $this->connection,
        ];
    }
}
