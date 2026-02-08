<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * HTTP response event payload.
 */
#[Internal]
final readonly class HttpResponsePayload implements ConsoleEvent
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public float $durationMs,
        public array $headers,
        public ?int $contentLength,
        public ?string $contentType,
        public ?string $routeName,
    ) {}

    public function eventType(): EventType
    {
        return EventType::HttpResponse;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'headers' => $this->headers,
            'content_length' => $this->contentLength,
            'content_type' => $this->contentType,
            'route_name' => $this->routeName,
        ];
    }
}
