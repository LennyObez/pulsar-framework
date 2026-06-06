<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a Live component for lazy loading.
 *
 * Lazy components render a placeholder on initial page load and defer
 * full rendering until the component scrolls into the viewport
 * (via IntersectionObserver on the frontend).
 *
 * Usage:
 *   #[Lazy]
 *   final class HeavyDashboard extends LiveComponent { ... }
 *
 *   #[Lazy(placeholder: '<div class="skeleton">Loading...</div>')]
 *   final class DataTable extends LiveComponent { ... }
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class Lazy
{
    /**
     * @param string $placeholder HTML shown while the component loads
     * @param bool $onInteraction If true, load on first user interaction instead of viewport entry
     */
    public function __construct(
        public string $placeholder = '<div data-live-lazy-placeholder></div>',
        public bool $onInteraction = false,
    ) {}
}
