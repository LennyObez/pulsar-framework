<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionHandler;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function is_array;

/**
 * Controller for bulk actions on resource records.
 */
#[Internal]
final readonly class BulkActionController
{
    public function __construct(
        private readonly BulkActionHandler $handler,
    ) {}

    public function execute(Request $request, string $resource): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        /** @var string $action */
        $action = $request->input('action', '') ?? '';
        /** @var list<string> $ids */
        $ids = $request->input('ids', []) ?? [];
        /** @var array<string, mixed> $parameters */
        $parameters = $request->input('parameters', []) ?? [];

        if (!is_array($ids)) {
            $ids = [];
        }
        if (!is_array($parameters)) {
            $parameters = [];
        }

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
            $result->result->success ? ResponseStatus::OK : ResponseStatus::UnprocessableEntity,
        );
    }
}
