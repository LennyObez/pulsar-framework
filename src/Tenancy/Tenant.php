<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

/**
 * Immutable tenant value object.
 */
readonly class Tenant
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $metadata = [],
    ) {}

    /**
     * Create a Tenant from a raw array (as stored in config).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $id, array $data): self
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $data['metadata'] ?? [];

        /** @var string $name */
        $name = $data['name'] ?? $id;

        return new self(
            id: $id,
            name: $name,
            metadata: $metadata,
        );
    }
}
