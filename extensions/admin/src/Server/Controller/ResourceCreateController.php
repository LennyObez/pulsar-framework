<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Auth\Identity\IdentityInterface;
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
    public function __construct(
        private CreateResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function form(ServerRequestInterface $request, string $resource): Response
    {
        $resourceDef = $this->registry->get($resource);

        return Response::html($this->renderView("Create {$resourceDef->label()}", [
            'resource' => $resourceDef,
            'data' => [],
            'mode' => 'create',
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    public function store(ServerRequestInterface $request, string $resource): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

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

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(string $title, array $templateData): string
    {
        extract(['title' => $title, 'content' => 'resource-form', 'templateData' => $templateData]);
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
