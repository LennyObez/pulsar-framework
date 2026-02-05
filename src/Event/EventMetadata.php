<?php

declare(strict_types=1);

namespace Pulsar\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;

use function is_string;

/**
 * Metadata for domain/integration events.
 *
 * Carries correlation, causation, actor, tenant, and occurrence timestamp.
 * Created from RequestContext for automatic propagation.
 */
#[Api(since: '1.0.0')]
final readonly class EventMetadata
{
    public DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public CorrelationId $correlationId,
        public CausationId $causationId,
        public ?string $actor = null,
        public ?string $tenantId = null,
        ?DateTimeImmutable $occurredAt = null,
        public array $attributes = [],
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    /**
     * Create event metadata from a request context.
     */
    #[NoDiscard]
    public static function fromRequestContext(RequestContext $context): self
    {
        return new self(
            correlationId: $context->correlationId,
            causationId: $context->causationId,
            actor: $context->actor,
            tenantId: $context->tenantId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'correlation_id' => $this->correlationId->value,
            'causation_id' => $this->causationId->value,
            'actor' => $this->actor,
            'tenant_id' => $this->tenantId,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'attributes' => $this->attributes,
        ];
    }

    /**
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
        $occurredAtRaw = $data['occurred_at'] ?? null;

        return new self(
            correlationId: CorrelationId::fromString(is_string($correlationId) ? $correlationId : ''),
            causationId: CausationId::fromString(is_string($causationId) ? $causationId : ''),
            actor: is_string($actor) ? $actor : null,
            tenantId: is_string($tenantId) ? $tenantId : null,
            occurredAt: is_string($occurredAtRaw) ? new DateTimeImmutable($occurredAtRaw) : null,
            attributes: $attributes,
        );
    }
}
