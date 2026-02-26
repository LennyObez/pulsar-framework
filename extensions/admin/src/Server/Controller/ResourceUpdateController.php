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
    public function __construct(
        private UpdateResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function form(ServerRequestInterface $request, string $resource, string $id): Response
    {
        $resourceDef = $this->registry->get($resource);

        return Response::html($this->renderView("Edit {$resourceDef->label()} #$id", [
            'resource' => $resourceDef,
            'data' => [],
            'mode' => 'edit',
            'id' => $id,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    public function update(ServerRequestInterface $request, string $resource, string $id): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

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

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(string $title, array $templateData): string
    {
        $content = 'resource-form';
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
