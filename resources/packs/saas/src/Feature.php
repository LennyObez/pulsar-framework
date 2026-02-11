<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Feature flag entity.
 *
 * Represents a feature that can be toggled per plan or per tenant.
 */
final class Feature
{
    /**
     * @param non-empty-string  $id          Unique feature identifier
     * @param non-empty-string  $slug        URL-safe feature slug
     * @param non-empty-string  $name        Feature display name
     * @param non-empty-string  $description Feature description
     * @param bool              $enabled     Whether the feature is globally enabled
     * @param list<string>      $plans       Plan slugs that include this feature
     * @param int               $rolloutPercentage Rollout percentage (0-100)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public bool $enabled = true,
        public readonly array $plans = [],
        public readonly int $rolloutPercentage = 100,
    ) {}

    public function isAvailableForPlan(string $planSlug): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->plans === []) {
            return true;
        }

        return in_array($planSlug, $this->plans, true);
    }

    public function isFullyRolledOut(): bool
    {
        return $this->rolloutPercentage >= 100;
    }
}
