<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Inbound webhook event DTO.
 */
#[Api]
final readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $id,
        public WebhookEventType $type,
        public DateTimeImmutable $createdAt,
        public array $data = [],
    ) {}

    /**
     * Build from a decoded JSON array.
     *
     * @param array<string, mixed> $payload
     */
    #[NoDiscard]
    public static function fromArray(array $payload): self
    {
        /** @var string $id */
        $id = $payload['id'] ?? '';

        /** @var string $type */
        $type = $payload['type'] ?? '';

        /** @var int $timestamp */
        $timestamp = $payload['created_at'] ?? 0;

        /** @var array<string, mixed> $data */
        $data = $payload['data'] ?? [];

        return new self(
            id: $id,
            type: WebhookEventType::from($type),
            createdAt: (new DateTimeImmutable())->setTimestamp($timestamp),
            data: $data,
        );
    }
}
