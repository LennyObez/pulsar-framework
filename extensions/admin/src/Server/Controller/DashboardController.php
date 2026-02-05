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
final readonly class DashboardController
{
    public function __construct(
        private DashboardHandler $handler,
        private AdminConfig $config,
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

        return Response::html($this->renderView([
            'widgets' => $result->widgets,
            'resources' => $result->resources,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(array $templateData): string
    {
        $title = 'Dashboard';
        $content = 'dashboard';
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
