<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Tenancy context event payload.
 *
 * Records tenant resolution events for audit and debugging.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
