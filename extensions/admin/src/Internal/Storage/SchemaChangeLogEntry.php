<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Pulsar\Api\Internal;

/**
 * A single entry in the schema change log.
 */
#[Internal]
final readonly class SchemaChangeLogEntry
{
    /**
     * @param list<string> $statements
     */
    public function __construct(
        public string $id,
        public string $operation,
        public string $table,
        public string $actor,
        public string $reason,
        public int $timestamp,
        public array $statements,
        public string $evidenceHash,
        public ?string $correlationId,
        public bool $success,
    ) {}
}
