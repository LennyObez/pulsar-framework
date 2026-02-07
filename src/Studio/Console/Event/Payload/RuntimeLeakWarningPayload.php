<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Emitted when the leak detector finds unreleased resources or excessive memory growth.
 */
#[Internal]
final readonly class RuntimeLeakWarningPayload implements ConsoleEvent
{
    /**
     * @param list<string> $warnings Individual warning messages
     */
    public function __construct(
        public array $warnings,
        public int $memoryDeltaBytes,
        public int $requestNumber,
    ) {}

    public function eventType(): EventType
    {
        return EventType::RuntimeLeakWarning;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'warnings' => $this->warnings,
            'memory_delta_bytes' => $this->memoryDeltaBytes,
            'request_number' => $this->requestNumber,
        ];
    }
}
