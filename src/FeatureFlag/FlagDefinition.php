<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable feature flag definition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FlagDefinition
{
    /**
     * @param list<string> $allowedTenants
     * @param list<string> $allowedUsers
     * @param list<string> $allowedEnvironments
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public FlagType $type = FlagType::Boolean,
        public int $percentage = 100,
        public array $allowedTenants = [],
        public array $allowedUsers = [],
        public array $allowedEnvironments = [],
        public string $description = '',
    ) {}

    /**
     * Create a FlagDefinition from a raw array.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     type?: string,
     *     percentage?: int,
     *     allowed_tenants?: list<string>,
     *     allowed_users?: list<string>,
     *     allowed_environments?: list<string>,
     *     description?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            enabled: (bool) ($data['enabled'] ?? false),
            type: FlagType::from($data['type'] ?? 'boolean'),
            percentage: $data['percentage'] ?? 100,
            allowedTenants: $data['allowed_tenants'] ?? [],
            allowedUsers: $data['allowed_users'] ?? [],
            allowedEnvironments: $data['allowed_environments'] ?? [],
            description: $data['description'] ?? '',
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'type' => $this->type->value,
            'percentage' => $this->percentage,
            'allowed_tenants' => $this->allowedTenants,
            'allowed_users' => $this->allowedUsers,
            'allowed_environments' => $this->allowedEnvironments,
            'description' => $this->description,
        ];
    }
}
