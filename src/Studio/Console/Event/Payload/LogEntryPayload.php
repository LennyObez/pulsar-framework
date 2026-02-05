<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Log entry event payload.
 */
#[Internal]
final readonly class LogEntryPayload implements ConsoleEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $level,
        public string $message,
        public string $channel,
        public array $context,
    ) {}

    public function eventType(): EventType
    {
        return EventType::LogEntry;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'channel' => $this->channel,
            'context' => $this->context,
        ];
    }
}
