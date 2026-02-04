<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

/**
 * Immutable feature flag definition.
 */
readonly class FlagDefinition
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        /** @var string $typeValue */
        $typeValue = $data['type'] ?? 'boolean';

        /** @var list<string> $allowedTenants */
        $allowedTenants = $data['allowed_tenants'] ?? [];

        /** @var list<string> $allowedUsers */
        $allowedUsers = $data['allowed_users'] ?? [];

        /** @var list<string> $allowedEnvironments */
        $allowedEnvironments = $data['allowed_environments'] ?? [];

        /** @var int $percentage */
        $percentage = $data['percentage'] ?? 100;

        /** @var string $description */
        $description = $data['description'] ?? '';

        return new self(
            name: $name,
            enabled: (bool) ($data['enabled'] ?? false),
            type: FlagType::from($typeValue),
            percentage: $percentage,
            allowedTenants: $allowedTenants,
            allowedUsers: $allowedUsers,
            allowedEnvironments: $allowedEnvironments,
            description: $description,
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
