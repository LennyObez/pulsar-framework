<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Outgoing HTTP request event payload.
 *
 * Prepared for future HTTP client subsystem. Active collector will be wired
 * when src/Http/Client/ is implemented via HttpClientInstrumentationInterface.
 */
#[Internal]
final readonly class OutgoingHttpPayload implements ConsoleEvent
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $method,
        public string $url,
        public ?int $statusCode,
        public float $durationMs,
        public ?string $errorMessage = null,
    ) {}

    public function eventType(): EventType
    {
        return EventType::OutgoingHttp;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
        ];
    }
}
