<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionHandler;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function is_array;

/**
 * Controller for bulk actions on resource records.
 */
#[Internal]
final readonly class BulkActionController
{
    public function __construct(
        private BulkActionHandler $handler,
    ) {}

    public function execute(ServerRequestInterface $request, string $resource): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $action = (string) ($body['action'] ?? '');
        $ids = $body['ids'] ?? [];
        $parameters = $body['parameters'] ?? [];

        if (!is_array($ids)) {
            $ids = [];
        }
        /** @var list<string> $ids */

        if (!is_array($parameters)) {
            $parameters = [];
        }
        /** @var array<string, mixed> $parameters */

        $context = new MutationContext(
            actor: $actor,
            reason: "Admin panel bulk action: $action",
        );

        $result = $this->handler->execute(new BulkActionRequest(
            resourceName: $resource,
            action: $action,
            ids: $ids,
            parameters: $parameters,
            context: $context,
        ));

        return Response::json(
            ['success' => $result->result->success, 'message' => $result->result->message, 'data' => $result->result->metadata],
            $result->result->success ? ResponseStatus::OK->value : ResponseStatus::UnprocessableEntity->value,
        );
    }
}
