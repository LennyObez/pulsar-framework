<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Internal;

/**
 * Collects all registered dashboard widgets and provides aggregated data
 * for the admin dashboard controller.
 *
 * @psalm-api Resolved from the DI container by the admin dashboard controller;
 *            not instantiated by name.
 */
#[Internal(reason: 'CMS dashboard service; implementation detail')]
final readonly class DashboardService
{
    /** @var list<DashboardWidgetInterface> */
    private array $widgets;

    /**
     * @param list<DashboardWidgetInterface> $widgets
     */
    public function __construct(array $widgets)
    {
        $this->widgets = $widgets;
    }

    /**
     * Collect data from all registered widgets.
     *
     * @return array<string, array{data: array<string, mixed>, template: string}>
     */
    public function collectWidgetData(): array
    {
        $result = [];

        foreach ($this->widgets as $widget) {
            $result[$widget->getName()] = [
                'data' => $widget->getData(),
                'template' => $widget->getTemplate(),
            ];
        }

        return $result;
    }

    /**
     * Get data for a specific widget by name.
     *
     * @return array{data: array<string, mixed>, template: string}|null
     */
    public function getWidgetData(string $name): ?array
    {
        foreach ($this->widgets as $widget) {
            if ($widget->getName() === $name) {
                return [
                    'data' => $widget->getData(),
                    'template' => $widget->getTemplate(),
                ];
            }
        }

        return null;
    }

    /**
     * Get the list of registered widget names.
     *
     * @return list<string>
     */
    public function getWidgetNames(): array
    {
        $names = [];
        foreach ($this->widgets as $widget) {
            $names[] = $widget->getName();
        }

        return $names;
    }
}
