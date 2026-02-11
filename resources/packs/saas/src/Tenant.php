<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Tenant entity.
 *
 * Represents a tenant (organization) in the multi-tenant system.
 * All data access is scoped to the tenant context.
 */
final class Tenant
{
    /**
     * @param non-empty-string        $id           Unique tenant identifier
     * @param non-empty-string        $name         Organization name
     * @param non-empty-string        $slug         URL-safe tenant slug (subdomain)
     * @param non-empty-string        $planId       Current billing plan identifier
     * @param non-empty-string|null   $region       Data residency region
     * @param TenantStatus            $status       Current tenant status
     * @param \DateTimeImmutable      $createdAt    Tenant creation timestamp
     * @param \DateTimeImmutable|null $trialEndsAt  Trial period end date
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $planId,
        public readonly ?string $region = null,
        public TenantStatus $status = TenantStatus::Active,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly ?\DateTimeImmutable $trialEndsAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isOnTrial(): bool
    {
        if ($this->trialEndsAt === null) {
            return false;
        }

        return $this->trialEndsAt > new \DateTimeImmutable();
    }

    public function isTrialExpired(): bool
    {
        if ($this->trialEndsAt === null) {
            return false;
        }

        return $this->trialEndsAt <= new \DateTimeImmutable();
    }
}
