<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets;

use Override;
use Pulsar\Api\Api;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketCategoryController;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketDashboardController;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketDetailController;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketListController;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketSettingsController;
use Pulsar\Extension\Tickets\Http\Controller\TicketController;
use Pulsar\Routing\RouterInterface;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Ticketing extension for support ticket management.
 *
 * Provides ticket submission, status tracking, agent assignment,
 * SLA enforcement, and admin back-office. Designed for regulated,
 * mission-critical support workflows.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final readonly class TicketsExtension implements ExtensionInterface, PreBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/tickets';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        if (!$container->has(TicketsConfig::class) && $container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'tickets.php')) {
                /** @psalm-suppress UnresolvableInclude */
                $ticketsData = require $configPath . DIRECTORY_SEPARATOR . 'tickets.php';

                if (is_array($ticketsData)) {
                    /** @var array<string, mixed> $ticketsData */
                    $ticketsConfig = TicketsConfig::fromArray($ticketsData);
                    $container->instance(TicketsConfig::class, $ticketsConfig);
                }
            }
        }

        if (!$container->has(TicketsConfig::class)) {
            $container->instance(TicketsConfig::class, TicketsConfig::fromArray([]));
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->registerPublicRoutes($router);
        $this->registerAdminRoutes($router);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            TicketsServiceProvider::class,
        ];
    }

    /**
     * Register public-facing routes for ticket submission and status lookup.
     */
    private function registerPublicRoutes(RouterInterface $router): void
    {
        $router->post('/support/tickets', [TicketController::class, 'create'], 'tickets.create');
        $router->get('/support/tickets/{number}', [TicketController::class, 'show'], 'tickets.show');
        $router->post('/support/tickets/{number}/messages', [TicketController::class, 'addMessage'], 'tickets.message');
    }

    /**
     * Register admin panel routes under /admin/tickets.
     */
    private function registerAdminRoutes(RouterInterface $router): void
    {
        $prefix = '/admin/tickets';

        // Dashboard
        $router->get($prefix, [TicketDashboardController::class, 'index'], 'tickets.admin.dashboard');

        // Ticket list
        $router->get("$prefix/list", [TicketListController::class, 'index'], 'tickets.admin.list');

        // Ticket detail and actions
        $router->get("$prefix/{id}", [TicketDetailController::class, 'show'], 'tickets.admin.detail');
        $router->post("$prefix/{id}/assign", [TicketDetailController::class, 'assign'], 'tickets.admin.assign');
        $router->put("$prefix/{id}/status", [TicketDetailController::class, 'changeStatus'], 'tickets.admin.change_status');
        $router->put("$prefix/{id}/priority", [TicketDetailController::class, 'changePriority'], 'tickets.admin.change_priority');
        $router->post("$prefix/{id}/messages", [TicketDetailController::class, 'addMessage'], 'tickets.admin.add_message');
        $router->post("$prefix/{id}/notes", [TicketDetailController::class, 'addNote'], 'tickets.admin.add_note');
        $router->post("$prefix/{id}/escalate", [TicketDetailController::class, 'escalate'], 'tickets.admin.escalate');

        // Categories
        $router->get("$prefix/categories", [TicketCategoryController::class, 'index'], 'tickets.admin.categories.index');
        $router->post("$prefix/categories", [TicketCategoryController::class, 'create'], 'tickets.admin.categories.create');
        $router->put("$prefix/categories/{id}", [TicketCategoryController::class, 'update'], 'tickets.admin.categories.update');
        $router->delete("$prefix/categories/{id}", [TicketCategoryController::class, 'delete'], 'tickets.admin.categories.delete');

        // Settings
        $router->get("$prefix/settings", [TicketSettingsController::class, 'show'], 'tickets.admin.settings');
    }
}
