<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Dashboard;

/**
 * Result DTO for the admin dashboard.
 */
final readonly class DashboardResult
{
    /**
     * @param list<array{id: string, label: string, size: string, data: array<string, mixed>}> $widgets
     * @param list<array{name: string, label: string, icon: string}> $resources
     */
    public function __construct(
        public array $widgets,
        public array $resources,
    ) {}
}
