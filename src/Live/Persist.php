<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a LiveProp as persistent across navigations.
 *
 * When wire:navigate is used for SPA-like navigation, properties
 * marked with #[Persist] retain their values across page transitions
 * instead of resetting to defaults.
 *
 * Usage:
 *   #[LiveProp(writable: true)]
 *   #[Persist]
 *   public string $searchQuery = '';
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Persist
{
    /**
     * @param string $key Custom storage key (defaults to component:property)
     */
    public function __construct(
        public string $key = '',
    ) {}
}
