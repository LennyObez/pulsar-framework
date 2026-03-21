<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawType = is_string($data['type'] ?? null) ? $data['type'] : '';
        $rawMeta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        /** @var array<string, mixed> $rawMeta */

        return new self(
            type: SchemaOperationType::from($rawType),
            table: is_string($data['table'] ?? null) ? $data['table'] : '',
            column: is_string($data['column'] ?? null) ? $data['column'] : null,
            metadata: $rawMeta,
        );
    }
}
