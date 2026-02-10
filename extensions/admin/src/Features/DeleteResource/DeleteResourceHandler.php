<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\DeleteResource;

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
 * Handles deleting a resource record.
 */
final readonly class DeleteResourceHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceMutatorInterface $mutator,
        private ActionHistoryStoreInterface $actionHistory,
    ) {}

    public function execute(DeleteResourceRequest $request): DeleteResourceResult
    {
        $resource = $this->registry->get($request->resourceName);

        $ops = $resource->operations();
        if (!in_array(ResourceOperation::Delete, $ops, true)) {
            throw new AdminException(
                "Delete operation not supported on resource \"{$request->resourceName}\"",
            );
        }

        $result = $this->mutator->delete($resource, $request->id, $request->context);

        $this->actionHistory->record(new ActionHistoryEntry(
            id: bin2hex(random_bytes(16)),
            action: 'delete',
            resourceName: $request->resourceName,
            recordId: $request->id,
            actor: $request->context->actor,
            timestamp: time(),
            success: $result->success,
            detail: $result->message,
        ));

        return new DeleteResourceResult(result: $result);
    }
}
