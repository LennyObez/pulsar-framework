<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;

/**
 * Contract for admin dashboard widgets.
 * @api
 */
#[Api(since: '1.0.0')]
interface WidgetInterface
{
    /**
     * Unique widget identifier.
     */
    public function id(): string;

    /**
     * Human-readable label.
     */
    public function label(): string;

    /**
     * Widget size: "small", "medium", "large".
     */
    public function size(): string;

    /**
     * Render the widget data.
     *
     * @return array<string, mixed>
     */
    public function render(): array;
}
