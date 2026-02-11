<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * HTTP request event payload.
 */
#[Internal]
final readonly class HttpRequestPayload implements ConsoleEvent
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $uri,
        public string $path,
        public array $headers,
        public ?string $clientIp,
        public ?string $userAgent,
        public ?string $contentType,
        public ?int $contentLength,
        public ?string $routeName,
        public ?string $queryString = null,
        public ?string $bodyPreview = null,
    ) {}

    public function eventType(): EventType
    {
        return EventType::HttpRequest;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'uri' => $this->uri,
            'path' => $this->path,
            'headers' => $this->headers,
            'client_ip' => $this->clientIp,
            'user_agent' => $this->userAgent,
            'content_type' => $this->contentType,
            'content_length' => $this->contentLength,
            'route_name' => $this->routeName,
            'query_string' => $this->queryString,
            'body_preview' => $this->bodyPreview,
        ];
    }
}
