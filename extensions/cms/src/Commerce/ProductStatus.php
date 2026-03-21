<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Lifecycle status for products in the commerce catalog.
 * @api
 */
#[Api(since: '1.0.0')]
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /**
     * Whether a transition from this status to the target is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::Draft => match ($target) {
                self::Active => true,
                default => false,
            },
            self::Active => match ($target) {
                self::Archived => true,
                default => false,
            },
            self::Archived => match ($target) {
                self::Draft => true,
                default => false,
            },
        };
    }

    /**
     * Whether products in this status are visible in the storefront.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
