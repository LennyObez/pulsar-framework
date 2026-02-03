<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Scheduler job run event payload.
 */
#[Internal]
final readonly class SchedulerRunPayload implements ConsoleEvent
{
    public function __construct(
        public string $jobName,
        public string $status,
        public float $durationMs,
        public ?string $errorMessage = null,
        public bool $missed = false,
    ) {}

    public function eventType(): EventType
    {
        return EventType::SchedulerRun;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'job_name' => $this->jobName,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
            'missed' => $this->missed,
        ];
    }
}
