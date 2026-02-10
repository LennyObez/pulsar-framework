<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\SavedViews;

use Pulsar\Extension\Admin\Domain\SavedView;

/**
 * Request DTO for saved view operations.
 */
final readonly class SavedViewsRequest
{
    /**
     * @param 'list'|'get'|'save'|'delete' $operation
     */
    public function __construct(
        public string $operation,
        public ?string $resourceName = null,
        public ?string $viewId = null,
        public ?SavedView $view = null,
    ) {}
}
