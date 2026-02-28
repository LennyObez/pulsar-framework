<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for deleting resource records.
 */
#[Internal]
final readonly class ResourceDeleteController
{
    use ExtractsRequestActor;

    public function __construct(
        private DeleteResourceHandler $handler,
    ) {}

    public function delete(ServerRequestInterface $request, string $resource, string $id): Response
    {
        $actor = $this->resolveActor($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $context = new MutationContext(
            actor: $actor,
            reason: (string) ($body['reason'] ?? 'Admin panel delete'),
        );

        $result = $this->handler->execute(new DeleteResourceRequest(
            resourceName: $resource,
            id: $id,
            context: $context,
        ));

        return Response::json(
            ['success' => $result->result->success, 'message' => $result->result->message],
            $result->result->success ? ResponseStatus::OK->value : ResponseStatus::NotFound->value,
        );
    }
}
