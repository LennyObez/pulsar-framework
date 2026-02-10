<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Widget;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;

/**
 * Dashboard widget showing record counts per registered resource.
 */
#[Internal]
final class ResourceCountWidget implements WidgetInterface
{
    public function __construct(
        private readonly ResourceRegistryInterface $registry,
        private readonly ResourceQueryInterface $query,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'resource_count';
    }

    #[Override]
    public function label(): string
    {
        return 'Resource Counts';
    }

    #[Override]
    public function size(): string
    {
        return 'medium';
    }

    #[Override]
    public function render(): array
    {
        $counts = [];
        foreach ($this->registry->all() as $name => $resource) {
            $counts[] = [
                'name' => $name,
                'label' => $resource->pluralLabel(),
                'icon' => $resource->icon(),
                'count' => $this->query->count($resource),
            ];
        }

        return ['resources' => $counts];
    }
}
