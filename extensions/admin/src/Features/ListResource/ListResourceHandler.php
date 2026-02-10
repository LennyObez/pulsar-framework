<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ListResource;

use function max;
use function min;

use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

/**
 * Handles listing resource records with pagination, filtering, and field visibility.
 */
final readonly class ListResourceHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceQueryInterface $query,
        private FieldVisibilityFilter $visibilityFilter,
        private AdminConfig $config,
    ) {}

    public function execute(ListResourceRequest $request): ListResourceResult
    {
        $resource = $this->registry->get($request->resourceName);

        $perPage = min(
            max(1, $request->perPage),
            $this->config->pagination->maxPerPage,
        );

        $result = $this->query->list(
            $resource,
            $request->filters,
            $request->sort,
            max(1, $request->page),
            $perPage,
        );

        $filteredData = array_map(
            fn(array $row): array => $this->visibilityFilter->filterForList($resource, $row),
            $result['data'],
        );

        $totalPages = $result['total'] > 0 ? (int) ceil($result['total'] / $perPage) : 1;

        return new ListResourceResult(
            data: $filteredData,
            total: $result['total'],
            page: $result['page'],
            perPage: $result['per_page'],
            totalPages: $totalPages,
        );
    }
}
