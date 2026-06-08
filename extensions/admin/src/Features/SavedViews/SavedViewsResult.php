<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\SavedViews;

use Pulsar\Extension\Admin\Domain\SavedView;

/**
 * Result DTO for saved view operations.
 *
 * @psalm-api Constructor-promoted properties consumed by admin controllers
 *            via reflection; Psalm cannot trace the read sites.
 */
final readonly class SavedViewsResult
{
    /**
     * @param list<SavedView> $views
     */
    public function __construct(
        public bool $success,
        public array $views = [],
        public ?SavedView $view = null,
    ) {}
}
