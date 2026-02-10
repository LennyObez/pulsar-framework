<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Admin dashboard controller.
 */
#[Internal]
final class DashboardController
{
    public function __construct(
        private readonly DashboardHandler $handler,
        private readonly AdminConfig $config,
    ) {}

    public function index(Request $request): Response
    {
        $result = $this->handler->execute(new DashboardRequest());

        if ($request->wantsJson()) {
            return Response::json([
                'widgets' => $result->widgets,
                'resources' => $result->resources,
            ]);
        }

        return Response::html($this->renderHtml($result->widgets, $result->resources));
    }

    /**
     * @param list<array{id: string, label: string, size: string, data: array<string, mixed>}> $widgets
     * @param list<array{name: string, label: string, icon: string}> $resources
     */
    private function renderHtml(array $widgets, array $resources): string
    {
        ob_start();
        $title = 'Dashboard';
        $content = 'dashboard';
        $templateData = ['widgets' => $widgets, 'resources' => $resources, 'schema_enabled' => $this->config->schema->enabled];
        include __DIR__ . '/../View/templates/admin/layout.php';
        return (string) ob_get_clean();
    }
}
