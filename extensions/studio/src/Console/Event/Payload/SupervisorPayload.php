<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Supervisor event payload for Studio Console.
 *
 * Records supervisor self-healing actions (worker recycle, stuck job
 * recovery, cache purge, connection reset) for observability.
 */
#[Internal]
final readonly class SupervisorPayload implements ConsoleEvent
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $type,
        public string $action,
        public bool $success,
        public array $details,
        public int $performedAt,
    ) {}

    public function eventType(): EventType
    {
        return EventType::Heartbeat;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'action' => $this->action,
            'success' => $this->success,
            'details' => $this->details,
            'performed_at' => $this->performedAt,
        ];
    }
}
