<?php

declare(strict_types=1);

namespace Pulsar\Context;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;
use function is_string;

/**
 * Immutable request context carrying correlation, causation, and request metadata.
 *
 * Propagated across HTTP, queue, scheduler, and CLI boundaries via ContextPropagator.
 * Clone-with mutators use PHP 8.5 clone() syntax.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RequestContext
{
    public DateTimeImmutable $timestamp;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public CorrelationId $correlationId,
        public CausationId $causationId,
        public ?string $actor = null,
        public ?string $tenantId = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $locale = null,
        ?DateTimeImmutable $timestamp = null,
        public array $attributes = [],
    ) {
        $this->timestamp = $timestamp ?? new DateTimeImmutable();
    }

    #[NoDiscard]
    public function withActor(string $actor): self
    {
        return clone($this, ['actor' => $actor]);
    }

    #[NoDiscard]
    public function withTenantId(string $tenantId): self
    {
        return clone($this, ['tenantId' => $tenantId]);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     */
    #[NoDiscard]
    public function withAttributes(array $attributes): self
    {
        return clone($this, ['attributes' => $attributes]);
    }

    /**
     * Serialize to array with snake_case keys.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'correlation_id' => $this->correlationId->value,
            'causation_id' => $this->causationId->value,
            'actor' => $this->actor,
            'tenant_id' => $this->tenantId,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'locale' => $this->locale,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            'attributes' => $this->attributes,
        ];
    }

    /**
     * Deserialize from array with snake_case keys.
     *
     * @param array{
     *     correlation_id?: string,
     *     causation_id?: string,
     *     actor?: string|null,
     *     tenant_id?: string|null,
     *     ip?: string|null,
     *     user_agent?: string|null,
     *     locale?: string|null,
     *     timestamp?: string|null,
     *     attributes?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $timestampRaw = $data['timestamp'] ?? null;
        $attributes = $data['attributes'] ?? null;

        return new self(
            correlationId: CorrelationId::fromString(Coerce::string($data['correlation_id'] ?? null)),
            causationId: CausationId::fromString(Coerce::string($data['causation_id'] ?? null)),
            actor: Coerce::nullableString($data['actor'] ?? null),
            tenantId: Coerce::nullableString($data['tenant_id'] ?? null),
            ip: Coerce::nullableString($data['ip'] ?? null),
            userAgent: Coerce::nullableString($data['user_agent'] ?? null),
            locale: Coerce::nullableString($data['locale'] ?? null),
            timestamp: is_string($timestampRaw) ? new DateTimeImmutable($timestampRaw) : null,
            attributes: is_array($attributes) ? $attributes : [],
        );
    }
}
