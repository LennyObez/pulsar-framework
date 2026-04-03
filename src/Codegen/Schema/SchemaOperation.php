<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function ksort;

/**
 * A single schema change operation produced by the diff engine.
 *
 * @phpstan-type OperationArray array{
 *     type: string,
 *     table: string,
 *     column: string|null,
 *     metadata: array<string, mixed>,
 * }
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SchemaOperation
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public SchemaOperationType $type,
        public string $table,
        public ?string $column = null,
        public array $metadata = [],
    ) {}

    /**
     * @return OperationArray
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $meta = $this->metadata;
        ksort($meta);

        return [
            'type' => $this->type->value,
            'table' => $this->table,
            'column' => $this->column,
            'metadata' => $meta,
        ];
    }

    /**
     * @param array{
     *     type?: string,
     *     table?: string,
     *     column?: string|null,
     *     metadata?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            type: SchemaOperationType::from($data['type'] ?? ''),
            table: $data['table'] ?? '',
            column: $data['column'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
