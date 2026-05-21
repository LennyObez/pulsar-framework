<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceHandler;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for updating resource records.
 */
#[Internal]
final readonly class ResourceUpdateController
{
    use ExtractsRequestActor;
    use RendersAdminLayout;

    public function __construct(
        private UpdateResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function form(string $resource, string $id): Response
    {
        $resourceDef = $this->registry->get($resource);

        return Response::html($this->renderAdminView("Edit {$resourceDef->label()} #$id", 'resource-form', [
            'resource' => $resourceDef,
            'data' => [],
            'mode' => 'edit',
            'id' => $id,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function update(ServerRequestInterface $request, string $resource, string $id): Response
    {
        $actor = $this->resolveActor($request);

        $context = new MutationContext(
            actor: $actor,
            reason: 'Admin panel update',
        );

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->handler->execute(new UpdateResourceRequest(
                resourceName: $resource,
                id: $id,
                data: $body,
                context: $context,
            ));

            return Response::json(
                ['success' => $result->result->success, 'message' => $result->result->message],
                $result->result->success ? ResponseStatus::OK->value : ResponseStatus::UnprocessableEntity->value,
            );
        } catch (ResourceValidationException $e) {
            return Response::validationError($e->violations);
        }
    }

}
