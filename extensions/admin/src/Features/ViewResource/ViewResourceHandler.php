<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ViewResource;

use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

/**
 * Handles viewing a single resource record.
 */
final readonly class ViewResourceHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceQueryInterface $query,
        private FieldVisibilityFilter $visibilityFilter,
    ) {}

    public function execute(ViewResourceRequest $request): ViewResourceResult
    {
        $resource = $this->registry->get($request->resourceName);
        $record = $this->query->find($resource, $request->id);

        if ($record === null) {
            throw ResourceNotFoundException::record($request->resourceName, $request->id);
        }

        $filtered = $this->visibilityFilter->filterForDetail($resource, $record);

        return new ViewResourceResult(data: $filtered);
    }
}
