<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Dashboard;

use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;

/**
 * Handles rendering the admin dashboard.
 */
final readonly class DashboardHandler
{
    /**
     * @param list<WidgetInterface> $widgets
     */
    public function __construct(
        private ResourceRegistryInterface $registry,
        private array $widgets,
    ) {}

    public function execute(): DashboardResult
    {
        $widgetData = array_map(
            static fn(WidgetInterface $widget): array => [
                'id' => $widget->id(),
                'label' => $widget->label(),
                'size' => $widget->size(),
                'data' => $widget->render(),
            ],
            $this->widgets,
        );

        $resources = [];
        foreach ($this->registry->all() as $name => $resource) {
            $resources[] = [
                'name' => $name,
                'label' => $resource->pluralLabel(),
                'icon' => $resource->icon(),
            ];
        }

        return new DashboardResult(
            widgets: $widgetData,
            resources: $resources,
        );
    }
}
