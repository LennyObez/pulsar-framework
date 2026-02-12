<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Snapshot;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

use function array_map;

/**
 * Immutable point-in-time capture of classified entity fields.
 *
 * Supports controls for SOX Section 302/404 by preserving auditable
 * snapshots of entity state with per-field classification metadata.
 */
#[Api(since: '1.0.0')]
final readonly class Snapshot
{
    /**
     * @param list<ClassifiedField> $fields
     */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public array $fields,
        public DateTimeImmutable $capturedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'fields' => array_map(
                static fn(ClassifiedField $field): array => $field->toArray(),
                $this->fields,
            ),
            'captured_at' => $this->capturedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * @param array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $fields = array_map(
            static fn(array $fieldData): ClassifiedField => new ClassifiedField(
                name: $fieldData['name'],
                value: $fieldData['value'],
                classification: DataClassification::from($fieldData['classification']),
            ),
            $data['fields'],
        );

        return new self(
            entityType: $data['entity_type'],
            entityId: $data['entity_id'],
            fields: $fields,
            capturedAt: new DateTimeImmutable($data['captured_at']),
        );
    }
}
