<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function count;
use function is_array;

/**
 * Ordered list of schema operations produced by the diff engine.
 *
 * Operations are in dependency order: creates before adds, drops after removes.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiffResult
{
    /**
     * @param list<SchemaOperation> $operations
     */
    public function __construct(
        public array $operations,
    ) {}

    /**
     * Whether the diff contains any operations.
     */
    #[NoDiscard]
    public function hasChanges(): bool
    {
        return count($this->operations) > 0;
    }

    /**
     * Filter operations by type.
     *
     * @return list<SchemaOperation>
     */
    #[NoDiscard]
    public function ofType(SchemaOperationType $type): array
    {
        $filtered = [];

        foreach ($this->operations as $operation) {
            if ($operation->type === $type) {
                $filtered[] = $operation;
            }
        }

        return $filtered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return array_map(
            static fn(SchemaOperation $op): array => $op->toArray(),
            $this->operations,
        );
    }

    /**
     * @param array<int, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $operations = [];

        foreach ($data as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $operations[] = SchemaOperation::fromArray($item);
            }
        }

        return new self($operations);
    }
}
