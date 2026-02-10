<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceHandler;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for updating resource records.
 */
#[Internal]
final readonly class ResourceUpdateController
{
    public function __construct(
        private readonly UpdateResourceHandler $handler,
        private readonly ResourceRegistryInterface $registry,
        private readonly AdminConfig $config,
    ) {}

    public function form(Request $request, string $resource, string $id): Response
    {
        $resourceDef = $this->registry->get($resource);

        ob_start();
        $title = "Edit {$resourceDef->label()} #$id";
        $content = 'resource-form';
        $templateData = [
            'resource' => $resourceDef,
            'data' => [],
            'mode' => 'edit',
            'id' => $id,
            'schema_enabled' => $this->config->schema->enabled,
        ];
        include __DIR__ . '/../View/templates/admin/layout.php';
        return Response::html((string) ob_get_clean());
    }

    public function update(Request $request, string $resource, string $id): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        $context = new MutationContext(
            actor: $actor,
            reason: 'Admin panel update',
        );

        try {
            $result = $this->handler->execute(new UpdateResourceRequest(
                resourceName: $resource,
                id: $id,
                data: $request->all(),
                context: $context,
            ));

            return Response::json(
                ['success' => $result->result->success, 'message' => $result->result->message],
                $result->result->success ? ResponseStatus::OK : ResponseStatus::UnprocessableEntity,
            );
        } catch (ResourceValidationException $e) {
            return Response::validationError($e->violations);
        }
    }
}
