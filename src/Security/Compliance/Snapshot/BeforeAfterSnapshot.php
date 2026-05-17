<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Snapshot;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Captures the before and after state of an entity for change tracking.
 *
 * Supports controls for SOX audit trail requirements by preserving
 * a paired snapshot of entity state across a mutation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BeforeAfterSnapshot
{
    public function __construct(
        public Snapshot $before,
        public Snapshot $after,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'before' => $this->before->toArray(),
            'after' => $this->after->toArray(),
        ];
    }

    /**
     * @param array{before: array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string}, after: array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string}} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            before: Snapshot::fromArray($data['before']),
            after: Snapshot::fromArray($data['after']),
        );
    }
}
