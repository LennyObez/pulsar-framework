<?php

declare(strict_types=1);

namespace Pulsar\Context;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

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

    /** @psalm-suppress MoreSpecificReturnType */
    #[NoDiscard]
    public function withActor(string $actor): self
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return clone($this, ['actor' => $actor]);
    }

    /** @psalm-suppress MoreSpecificReturnType */
    #[NoDiscard]
    public function withTenantId(string $tenantId): self
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return clone($this, ['tenantId' => $tenantId]);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @psalm-suppress MoreSpecificReturnType
     */
    #[NoDiscard]
    public function withAttributes(array $attributes): self
    {
        /** @psalm-suppress LessSpecificReturnStatement */
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

        return new self(
            correlationId: CorrelationId::fromString($data['correlation_id'] ?? ''),
            causationId: CausationId::fromString($data['causation_id'] ?? ''),
            actor: $data['actor'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            ip: $data['ip'] ?? null,
            userAgent: $data['user_agent'] ?? null,
            locale: $data['locale'] ?? null,
            timestamp: $timestampRaw !== null ? new DateTimeImmutable($timestampRaw) : null,
            attributes: $data['attributes'] ?? [],
        );
    }
}
