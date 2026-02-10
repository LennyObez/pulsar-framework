<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for deleting resource records.
 */
#[Internal]
final class ResourceDeleteController
{
    public function __construct(
        private readonly DeleteResourceHandler $handler,
    ) {}

    public function delete(Request $request, string $resource, string $id): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        $context = new MutationContext(
            actor: $actor,
            reason: $request->input('reason', 'Admin panel delete') ?? 'Admin panel delete',
        );

        $result = $this->handler->execute(new DeleteResourceRequest(
            resourceName: $resource,
            id: $id,
            context: $context,
        ));

        return Response::json(
            ['success' => $result->result->success, 'message' => $result->result->message],
            $result->result->success ? ResponseStatus::OK : ResponseStatus::NotFound,
        );
    }
}
