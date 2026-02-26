<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Api;

/**
 * Contract for CMS dashboard widgets.
 *
 * Each widget provides a name, template identifier, and computed data
 * for rendering in the admin dashboard.
 */
#[Api(since: '1.0.0')]
interface DashboardWidgetInterface
{
    /**
     * Unique widget identifier used as the array key in dashboard data.
     */
    public function getName(): string;

    /**
     * Compute and return widget data for rendering.
     *
     * @return array<string, mixed>
     */
    public function getData(): array;

    /**
     * Template name used for rendering this widget.
     */
    public function getTemplate(): string;
}
