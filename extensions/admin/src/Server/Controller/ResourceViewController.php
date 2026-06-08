<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceHandler;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function str_contains;

/**
 * Controller for viewing a single resource record.
 */
#[Internal]
final readonly class ResourceViewController
{
    use RendersAdminLayout;

    public function __construct(
        private ViewResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function view(ServerRequestInterface $request, string $resource, string $id): Response
    {
        try {
            $result = $this->handler->execute(new ViewResourceRequest(
                resourceName: $resource,
                id: $id,
            ));
        } catch (ResourceNotFoundException $e) {
            return Response::json(
                ['error' => $e->getMessage()],
                ResponseStatus::NotFound->value,
            );
        } catch (DatabaseException $e) {
            return Response::json(
                ['error' => 'Query failed for this resource.', 'message' => $e->getMessage()],
                422,
            );
        }

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(['data' => $result->data]);
        }

        $resourceDef = $this->registry->get($resource);

        return Response::html($this->renderAdminView("{$resourceDef->label()} #$id", 'resource-view', [
            'resource' => $resourceDef,
            'data' => $result->data,
            'id' => $id,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

}
