<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for creating resource records.
 */
#[Internal]
final readonly class ResourceCreateController
{
    use ExtractsRequestActor;
    use RendersAdminLayout;

    public function __construct(
        private CreateResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function form(ServerRequestInterface $request, string $resource): Response
    {
        $resourceDef = $this->registry->get($resource);

        return Response::html($this->renderAdminView("Create {$resourceDef->label()}", 'resource-form', [
            'resource' => $resourceDef,
            'data' => [],
            'mode' => 'create',
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    public function store(ServerRequestInterface $request, string $resource): Response
    {
        $actor = $this->resolveActor($request);

        $context = new MutationContext(
            actor: $actor,
            reason: 'Admin panel create',
        );

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->handler->execute(new CreateResourceRequest(
                resourceName: $resource,
                data: $body,
                context: $context,
            ));

            return Response::json(
                ['success' => $result->result->success, 'message' => $result->result->message, 'data' => $result->result->metadata],
                $result->result->success ? ResponseStatus::Created->value : ResponseStatus::UnprocessableEntity->value,
            );
        } catch (ResourceValidationException $e) {
            return Response::validationError($e->violations);
        }
    }

}
