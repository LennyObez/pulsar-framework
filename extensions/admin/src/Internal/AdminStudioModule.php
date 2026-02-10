<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Contracts\StudioModuleInterface;
use Pulsar\Extension\Studio\Contracts\StudioNavEntry;
use Pulsar\Routing\RouterInterface;

/**
 * Studio module integration for the admin panel.
 *
 * Registers the admin panel as a module in the Studio sidebar,
 * providing navigation entries for dashboard, resources, and activity.
 */
#[Internal]
final readonly class AdminStudioModule implements StudioModuleInterface
{
    #[Override]
    public function moduleId(): string
    {
        return 'admin';
    }

    #[Override]
    public function label(): string
    {
        return 'Admin Panel';
    }

    #[Override]
    public function icon(): string
    {
        return 'shield';
    }

    #[Override]
    public function navEntries(): array
    {
        return [
            new StudioNavEntry(
                label: 'Dashboard',
                href: '/admin',
                icon: 'home',
                order: 0,
            ),
            new StudioNavEntry(
                label: 'Resources',
                href: '/admin/resources',
                icon: 'database',
                order: 1,
            ),
            new StudioNavEntry(
                label: 'Activity Log',
                href: '/admin/history',
                icon: 'activity',
                order: 2,
            ),
        ];
    }

    #[Override]
    public function registerRoutes(RouterInterface $router): void
    {
        // Routes are registered by AdminExtension directly
    }

    #[Override]
    public function routePrefix(): string
    {
        return '/studio/admin';
    }

    #[Override]
    public function navOrder(): int
    {
        return 50;
    }
}
