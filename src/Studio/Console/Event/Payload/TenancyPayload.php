<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Tenancy context event payload.
 *
 * Records tenant resolution events for audit and debugging.
 */
#[Internal]
final readonly class TenancyPayload implements ConsoleEvent
{
    public function __construct(
        public string $tenantHash,
        public string $resolverStrategy,
        public bool $resolved,
    ) {}

    public function eventType(): EventType
    {
        return EventType::Heartbeat;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'tenant_hash' => $this->tenantHash,
            'resolver_strategy' => $this->resolverStrategy,
            'resolved' => $this->resolved,
        ];
    }
}
