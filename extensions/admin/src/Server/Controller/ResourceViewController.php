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
final class ResourceViewController
{
    public function __construct(
        private readonly ViewResourceHandler $handler,
        private readonly ResourceRegistryInterface $registry,
        private readonly AdminConfig $config,
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
        ob_start();
        $title = "{$resourceDef->label()} #{$id}";
        $content = 'resource-view';
        $templateData = [
            'resource' => $resourceDef,
            'data' => $result->data,
            'id' => $id,
            'schema_enabled' => $this->config->schema->enabled,
        ];
        include __DIR__ . '/../View/templates/admin/layout.php';
        return Response::html((string) ob_get_clean());
    }
}
