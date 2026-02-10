<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for creating resource records.
 */
#[Internal]
final class ResourceCreateController
{
    public function __construct(
        private readonly CreateResourceHandler $handler,
        private readonly ResourceRegistryInterface $registry,
        private readonly AdminConfig $config,
    ) {}

    public function form(Request $request, string $resource): Response
    {
        $resourceDef = $this->registry->get($resource);

        ob_start();
        $title = "Create {$resourceDef->label()}";
        $content = 'resource-form';
        $templateData = [
            'resource' => $resourceDef,
            'data' => [],
            'mode' => 'create',
            'schema_enabled' => $this->config->schema->enabled,
        ];
        include __DIR__ . '/../View/templates/admin/layout.php';
        return Response::html((string) ob_get_clean());
    }

    public function store(Request $request, string $resource): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        $context = new MutationContext(
            actor: $actor,
            reason: 'Admin panel create',
        );

        try {
            $result = $this->handler->execute(new CreateResourceRequest(
                resourceName: $resource,
                data: $request->all(),
                context: $context,
            ));

            return Response::json(
                ['success' => $result->result->success, 'message' => $result->result->message, 'data' => $result->result->metadata],
                $result->result->success ? ResponseStatus::Created : ResponseStatus::UnprocessableEntity,
            );
        } catch (ResourceValidationException $e) {
            return Response::validationError($e->violations);
        }
    }
}
