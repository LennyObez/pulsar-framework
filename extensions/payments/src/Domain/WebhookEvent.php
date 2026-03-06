<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use ValueError;

/**
 * Inbound webhook event DTO.
 */
#[Api(since: '1.0.0')]
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
     *
     * @throws ValueError If the event type is not a valid WebhookEventType
     */
    #[NoDiscard]
    public static function fromArray(array $payload): self
    {
        /** @var string $id */
        $id = $payload['id'] ?? '';

        /** @var string $type */
        $type = $payload['type'] ?? '';

        // F22.7: cast to int before concatenation. The previous
        // `?? 0` short-circuit only protected the missing-key case;
        // a non-numeric `created_at` ("garbage", "1700-01-01", or any
        // attacker-controlled JSON value) flowed through as a string
        // and reached `new DateTimeImmutable('@<string>')`, which on
        // PHP 8.3+ raises `DateMalformedStringException` and surfaces
        // as 500 because the upstream handler only catches
        // JsonException | ValueError. The (int) cast normalises any
        // non-numeric value to 0 (Unix epoch — recognisable as
        // malformed) and keeps the constructor signature in the
        // safe `int|numeric-string` shape it expects.
        $timestamp = (int) ($payload['created_at'] ?? 0);

        /** @var array<string, mixed> $data */
        $data = $payload['data'] ?? [];

        return new self(
            id: $id,
            type: WebhookEventType::from($type),
            createdAt: new DateTimeImmutable('@' . $timestamp),
            data: $data,
        );
    }
}
