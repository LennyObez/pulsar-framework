<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\CreateResource;

use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

use function bin2hex;
use function random_bytes;
use function time;

/**
 * Handles creating a new resource record with validation and audit.
 */
final readonly class CreateResourceHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceMutatorInterface $mutator,
        private ActionHistoryStoreInterface $actionHistory,
    ) {}

    public function execute(CreateResourceRequest $request): CreateResourceResult
    {
        $resource = $this->registry->get($request->resourceName);

        $ops = $resource->operations();
        if (!in_array(ResourceOperation::Create, $ops, true)) {
            throw new \Pulsar\Extension\Admin\Exception\AdminException(
                "Create operation not supported on resource \"{$request->resourceName}\"",
            );
        }

        $violations = $this->validate($resource, $request->data);
        if ($violations !== []) {
            throw ResourceValidationException::fromViolations($violations);
        }

        $result = $this->mutator->create($resource, $request->data, $request->context);

        $this->actionHistory->record(new ActionHistoryEntry(
            id: bin2hex(random_bytes(16)),
            action: 'create',
            resourceName: $request->resourceName,
            recordId: $result->metadata['id'] ?? null,
            actor: $request->context->actor,
            timestamp: time(),
            success: $result->success,
            detail: $result->message,
        ));

        return new CreateResourceResult(result: $result);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{field: string, message: string, rule: string}>
     */
    private function validate(\Pulsar\Extension\Admin\Contracts\DataResourceInterface $resource, array $data): array
    {
        $violations = [];
        foreach ($resource->fields() as $field) {
            if (!$field->editable) {
                continue;
            }
            $value = $data[$field->name] ?? null;
            foreach ($field->rules as $rule) {
                $error = $rule->validate($value, $field->label);
                if ($error !== null) {
                    $violations[] = [
                        'field' => $field->name,
                        'message' => $error,
                        'rule' => $rule->rule,
                    ];
                }
            }
        }
        return $violations;
    }
}
