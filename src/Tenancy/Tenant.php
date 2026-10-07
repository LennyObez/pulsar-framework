<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function preg_match;
use function sprintf;

/**
 * Immutable tenant value object.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Tenant
{
    /**
     * Allowed pattern for tenant IDs: alphanumeric, hyphens, and underscores only.
     */
    private const string ID_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * @param array<string, mixed> $metadata
     *
     * @throws InvalidArgumentException If the tenant ID contains invalid characters.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $metadata = [],
    ) {
        if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Tenant ID must be non-empty and contain only alphanumeric characters, hyphens, and underscores. Got: "%s"',
                $id,
            ));
        }
    }

    /**
     * Create a Tenant from a raw array (as stored in config).
     *
     * @param array{
     *     name?: string,
     *     metadata?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(string $id, array $data): self
    {
        return new self(
            id: $id,
            name: $data['name'] ?? $id,
            metadata: $data['metadata'] ?? [],
        );
    }
}
