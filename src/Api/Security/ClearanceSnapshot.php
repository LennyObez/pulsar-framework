<?php

declare(strict_types=1);

namespace Pulsar\Api\Security;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Security\Compliance\DataClassification;

use function array_intersect;
use function in_array;

/**
 * Immutable snapshot of a requester's authorization clearance.
 *
 * Computed once at the request boundary from the authenticated identity.
 * Remains immutable for the entire request lifetime to ensure deterministic
 * field exposure: the same request always produces the same fields.
 */
#[Api(since: '1.0.0')]
final readonly class ClearanceSnapshot
{
    /**
     * @param string $snapshotId Unique identifier for this snapshot (correlation ID)
     * @param DataClassification $maxClassification Highest classification the requester can access
     * @param list<string> $permissions All effective permissions for this requester
     * @param list<string> $roles All effective roles for this requester
     * @param bool $authenticated Whether the requester is authenticated
     */
    public function __construct(
        public string $snapshotId,
        public DataClassification $maxClassification,
        public array $permissions,
        public array $roles,
        public bool $authenticated,
    ) {}

    /**
     * Build a clearance snapshot from an authenticated identity.
     *
     * @param list<string> $resolvedPermissions Pre-resolved effective permissions (from Gate)
     */
    #[NoDiscard]
    public static function fromIdentity(
        IdentityInterface $identity,
        string $snapshotId,
        array $resolvedPermissions,
        DataClassification $maxClassification = DataClassification::Public,
    ): self {
        return new self(
            snapshotId: $snapshotId,
            maxClassification: $maxClassification,
            permissions: $resolvedPermissions,
            roles: $identity->roles(),
            authenticated: $identity->isAuthenticated(),
        );
    }

    /**
     * Build an anonymous clearance snapshot with Public-only access.
     */
    #[NoDiscard]
    public static function anonymous(string $snapshotId): self
    {
        return new self(
            snapshotId: $snapshotId,
            maxClassification: DataClassification::Public,
            permissions: [],
            roles: [],
            authenticated: false,
        );
    }

    /**
     * Check if this clearance has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Check if this clearance has any of the specified roles.
     *
     * @param list<string> $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return array_intersect($this->roles, $roles) !== [];
    }

    /**
     * Check if this clearance meets the required classification level.
     */
    public function meetsClassification(DataClassification $required): bool
    {
        return self::classificationLevel($this->maxClassification) >= self::classificationLevel($required);
    }

    /**
     * Map classification to a numeric level for comparison.
     */
    private static function classificationLevel(DataClassification $classification): int
    {
        return match ($classification) {
            DataClassification::Public => 0,
            DataClassification::Internal => 1,
            DataClassification::Confidential => 2,
            DataClassification::Restricted => 3,
        };
    }
}
