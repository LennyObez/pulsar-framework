<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Feature flag evaluation event payload.
 */
#[Internal]
final readonly class FeatureFlagPayload implements ConsoleEvent
{
    public function __construct(
        public string $flagName,
        public bool $result,
        public string $reason,
        public ?string $contextIdentifier = null,
    ) {}

    public function eventType(): EventType
    {
        return EventType::FeatureFlagEval;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'flag_name' => $this->flagName,
            'result' => $this->result,
            'reason' => $this->reason,
            'context_identifier' => $this->contextIdentifier,
        ];
    }
}
