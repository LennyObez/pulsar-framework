<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use function array_key_exists;
use function in_array;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

/**
 * Immutable value object representing an authenticated identity.
 */
#[Api]
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

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function displayName(): string
    {
        return $this->displayName;
    }

    #[Override]
    public function roles(): array
    {
        return $this->roles;
    }

    #[Override]
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    #[Override]
    public function twoFactorStatus(): TwoFactorStatus
    {
        return $this->twoFactorStatus;
    }

    #[Override]
    public function isAuthenticated(): true
    {
        return true;
    }

    #[Override]
    public function attributes(): array
    {
        return $this->attributes;
    }

    #[Override]
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
    #[NoDiscard]
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
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement -- Psalm does not yet infer clone() return type
     */
    #[NoDiscard]
    public function withTwoFactorStatus(TwoFactorStatus $status): self
    {
        return clone($this, ['twoFactorStatus' => $status]);
    }
}
