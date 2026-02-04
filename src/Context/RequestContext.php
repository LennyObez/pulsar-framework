<?php

declare(strict_types=1);

namespace Pulsar\Context;

use DateTimeImmutable;

use function is_string;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable request context carrying correlation, causation, and request metadata.
 *
 * Propagated across HTTP, queue, scheduler, and CLI boundaries via ContextPropagator.
 * Clone-with mutators use PHP 8.5 clone() syntax.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $data['attributes'] ?? [];

        $correlationId = $data['correlation_id'] ?? '';
        $causationId = $data['causation_id'] ?? '';
        $actor = $data['actor'] ?? null;
        $tenantId = $data['tenant_id'] ?? null;
        $ip = $data['ip'] ?? null;
        $userAgent = $data['user_agent'] ?? null;
        $locale = $data['locale'] ?? null;
        $timestampRaw = $data['timestamp'] ?? null;

        return new self(
            correlationId: CorrelationId::fromString(is_string($correlationId) ? $correlationId : ''),
            causationId: CausationId::fromString(is_string($causationId) ? $causationId : ''),
            actor: is_string($actor) ? $actor : null,
            tenantId: is_string($tenantId) ? $tenantId : null,
            ip: is_string($ip) ? $ip : null,
            userAgent: is_string($userAgent) ? $userAgent : null,
            locale: is_string($locale) ? $locale : null,
            timestamp: is_string($timestampRaw) ? new DateTimeImmutable($timestampRaw) : null,
            attributes: $attributes,
        );
    }
}
