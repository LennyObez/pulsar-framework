<?php

declare(strict_types=1);

namespace Pulsar\Event;

use DateMalformedStringException;
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
 * @api
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
     * @param array{
     *     correlation_id?: string,
     *     causation_id?: string,
     *     actor?: string|null,
     *     tenant_id?: string|null,
     *     occurred_at?: string|null,
     *     attributes?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAtRaw = $data['occurred_at'] ?? null;

        return new self(
            correlationId: CorrelationId::fromString($data['correlation_id'] ?? ''),
            causationId: CausationId::fromString($data['causation_id'] ?? ''),
            actor: $data['actor'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            occurredAt: is_string($occurredAtRaw) ? self::parseOccurredAt($occurredAtRaw) : null,
            attributes: $data['attributes'] ?? [],
        );
    }

    private static function parseOccurredAt(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (DateMalformedStringException) {
            return null;
        }
    }
}
