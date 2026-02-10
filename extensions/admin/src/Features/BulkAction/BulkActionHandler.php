<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\BulkAction;

use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

use function bin2hex;
use function random_bytes;
use function time;

/**
 * Handles executing a bulk action on multiple resource records.
 */
final readonly class BulkActionHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceMutatorInterface $mutator,
        private ActionHistoryStoreInterface $actionHistory,
    ) {}

    public function execute(BulkActionRequest $request): BulkActionResult
    {
        $resource = $this->registry->get($request->resourceName);

        $ops = $resource->operations();
        if (!in_array(ResourceOperation::BulkAction, $ops, true)) {
            throw new AdminException(
                "Bulk actions not supported on resource \"{$request->resourceName}\"",
            );
        }

        $result = $this->mutator->bulkAction(
            $resource,
            $request->action,
            $request->ids,
            $request->parameters,
            $request->context,
        );

        $this->actionHistory->record(new ActionHistoryEntry(
            id: bin2hex(random_bytes(16)),
            action: "bulk.{$request->action}",
            resourceName: $request->resourceName,
            recordId: null,
            actor: $request->context->actor,
            timestamp: time(),
            success: $result->success,
            detail: $result->message,
        ));

        return new BulkActionResult(result: $result);
    }
}
