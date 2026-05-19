<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use ValueError;

use function is_array;
use function is_string;

/**
 * Inbound webhook event DTO.
 * @api
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
     * F22.13: validates the payload shape explicitly so a partial
     * deserialisation (e.g. `{"id": null, "data": "junk"}`) fails
     * fast at the boundary instead of constructing a domain object
     * with empty/invalid fields that would only break later in the
     * pipeline. Throws `InvalidArgumentException` (caller's
     * `Throwable` catch already handles it).
     *
     * @param array{
     *     id?: string|null,
     *     type?: string|null,
     *     created_at?: int|string|float,
     *     data?: array<string, mixed>,
     * } $payload
     *
     * @throws InvalidArgumentException If required fields are missing or wrong-typed.
     * @throws ValueError                If the event type is not a valid WebhookEventType.
     */
    #[NoDiscard]
    public static function fromArray(array $payload): self
    {
        $rawId = $payload['id'] ?? null;
        if (!is_string($rawId) || $rawId === '') {
            throw new InvalidArgumentException('WebhookEvent.id missing or not a non-empty string');
        }
        $id = $rawId;

        $rawType = $payload['type'] ?? null;
        if (!is_string($rawType) || $rawType === '') {
            throw new InvalidArgumentException('WebhookEvent.type missing or not a non-empty string');
        }
        $type = $rawType;

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
        $createdAtRaw = $payload['created_at'] ?? 0;
        $timestamp = is_numeric($createdAtRaw) ? (int) $createdAtRaw : 0;

        $rawData = $payload['data'] ?? [];
        if (!is_array($rawData)) {
            throw new InvalidArgumentException('WebhookEvent.data is not an array');
        }
        /** @var array<string, mixed> $data */
        $data = $rawData;

        return new self(
            id: $id,
            type: WebhookEventType::from($type),
            createdAt: new DateTimeImmutable('@' . $timestamp),
            data: $data,
        );
    }
}
