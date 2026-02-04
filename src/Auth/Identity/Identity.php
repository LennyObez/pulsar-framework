<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use function array_key_exists;
use function in_array;

/**
 * Immutable value object representing an authenticated identity.
 */
readonly class Identity implements IdentityInterface
{
    /**
     * @param list<string> $roles
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $id,
        private string $displayName,
        private array $roles = [],
        private TwoFactorStatus $twoFactorStatus = TwoFactorStatus::Disabled,
        private array $attributes = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function twoFactorStatus(): TwoFactorStatus
    {
        return $this->twoFactorStatus;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /**
     * Serialize this identity to an array for session storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'roles' => $this->roles,
            'two_factor_status' => $this->twoFactorStatus->value,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * Reconstitute an identity from a serialized array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $id */
        $id = $data['id'] ?? '';
        /** @var string $displayName */
        $displayName = $data['display_name'] ?? '';
        /** @var list<string> $roles */
        $roles = $data['roles'] ?? [];
        /** @var string $twoFactorStatusValue */
        $twoFactorStatusValue = $data['two_factor_status'] ?? TwoFactorStatus::Disabled->value;
        /** @var array<string, mixed> $attributes */
        $attributes = $data['attributes'] ?? [];

        return new self(
            id: $id,
            displayName: $displayName,
            roles: $roles,
            twoFactorStatus: TwoFactorStatus::from($twoFactorStatusValue),
            attributes: $attributes,
        );
    }

    /**
     * Return a new identity with the given two-factor status.
     */
    public function withTwoFactorStatus(TwoFactorStatus $status): self
    {
        return new self(
            id: $this->id,
            displayName: $this->displayName,
            roles: $this->roles,
            twoFactorStatus: $status,
            attributes: $this->attributes,
        );
    }
}
