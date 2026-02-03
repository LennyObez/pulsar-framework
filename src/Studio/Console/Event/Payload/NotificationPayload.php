<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Notification event payload.
 *
 * Prepared for future notification/mail subsystem. Active collector will be wired
 * when the notification subsystem is implemented via NotificationInstrumentationInterface.
 */
#[Internal]
final readonly class NotificationPayload implements ConsoleEvent
{
    public function __construct(
        public string $channel,
        public string $recipient,
        public string $status,
        public ?float $durationMs = null,
        public ?string $errorMessage = null,
    ) {}

    public function eventType(): EventType
    {
        return EventType::Notification;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
        ];
    }
}
