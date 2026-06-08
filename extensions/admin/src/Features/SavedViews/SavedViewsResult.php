<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\SavedViews;

use Pulsar\Extension\Admin\Domain\SavedView;

/**
 * Result DTO for saved view operations.
 */
final readonly class SavedViewsResult
{
    /**
     * @param list<SavedView> $views
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public bool $success,
        public array $views = [],
        public ?SavedView $view = null,
    ) {}
}
