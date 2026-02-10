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
     * @param 'list'|'get'|'save'|'delete' $operation Operation to perform
     * @param string|null $resourceName Target resource name (required for 'list' and 'save')
     * @param string|null $viewId View identifier (required for 'get' and 'delete')
     * @param SavedView|null $view View data (required for 'save')
     */
    public function __construct(
        public string $operation,
        public ?string $resourceName = null,
        public ?string $viewId = null,
        public ?SavedView $view = null,
    ) {}
}
