<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceHandler;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for viewing a single resource record.
 */
#[Internal]
final readonly class ResourceViewController
{
    public function __construct(
        private ViewResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function view(Request $request, string $resource, string $id): Response
    {
        try {
            $result = $this->handler->execute(new ViewResourceRequest(
                resourceName: $resource,
                id: $id,
            ));
        } catch (ResourceNotFoundException $e) {
            return Response::json(
                ['error' => $e->getMessage()],
                ResponseStatus::NotFound,
            );
        }

        if ($request->wantsJson()) {
            return Response::json(['data' => $result->data]);
        }

        $resourceDef = $this->registry->get($resource);

        return Response::html($this->renderView("{$resourceDef->label()} #$id", [
            'resource' => $resourceDef,
            'data' => $result->data,
            'id' => $id,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(string $title, array $templateData): string
    {
        $content = 'resource-view';
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
