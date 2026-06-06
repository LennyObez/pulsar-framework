<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Cache operation event payload.
 *
 * Prepared for future cache subsystem. Active collector will be wired
 * when src/Cache/ is implemented via CacheInstrumentationInterface.
 */
#[Internal]
final readonly class CacheOperationPayload implements ConsoleEvent
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $operation,
        public string $key,
        public bool $hit,
        public float $durationMs,
        public ?string $store = null,
        public ?int $ttl = null,
    ) {}

    public function eventType(): EventType
    {
        return EventType::CacheOperation;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'key' => $this->key,
            'hit' => $this->hit,
            'duration_ms' => $this->durationMs,
            'store' => $this->store,
            'ttl' => $this->ttl,
        ];
    }
}
