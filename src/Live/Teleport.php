<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Attribute;
use Pulsar\Api\Api;

/**
 * Teleports a component's rendered output to a different DOM location.
 *
 * The component renders at its logical position in the component tree,
 * but the frontend moves the output to the specified CSS selector target.
 * Useful for modals, toasts, and overlays that must escape their parent's
 * stacking context.
 *
 * Usage:
 *   #[Teleport(to: '#modal-root')]
 *   final class ConfirmDialog extends LiveComponent { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class Teleport
{
    /**
     * @param string $to CSS selector of the teleport target element
     */
    public function __construct(
        public string $to,
    ) {}
}
