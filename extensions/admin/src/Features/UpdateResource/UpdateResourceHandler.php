<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\UpdateResource;

use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

use function bin2hex;
use function random_bytes;
use function time;

/**
 * Handles updating an existing resource record with validation and audit.
 */
final readonly class UpdateResourceHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceMutatorInterface $mutator,
        private ActionHistoryStoreInterface $actionHistory,
    ) {}

    public function execute(UpdateResourceRequest $request): UpdateResourceResult
    {
        $resource = $this->registry->get($request->resourceName);

        $ops = $resource->operations();
        if (!in_array(ResourceOperation::Update, $ops, true)) {
            throw new AdminException(
                "Update operation not supported on resource \"{$request->resourceName}\"",
            );
        }

        $violations = $this->validate($resource, $request->data);
        if ($violations !== []) {
            throw ResourceValidationException::fromViolations($violations);
        }

        $result = $this->mutator->update($resource, $request->id, $request->data, $request->context);

        $this->actionHistory->record(new ActionHistoryEntry(
            id: bin2hex(random_bytes(16)),
            action: 'update',
            resourceName: $request->resourceName,
            recordId: $request->id,
            actor: $request->context->actor,
            timestamp: time(),
            success: $result->success,
            detail: $result->message,
        ));

        return new UpdateResourceResult(result: $result);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{field: string, message: string, rule: string}>
     */
    private function validate(DataResourceInterface $resource, array $data): array
    {
        $violations = [];
        foreach ($resource->fields() as $field) {
            if (!$field->editable || !array_key_exists($field->name, $data)) {
                continue;
            }
            $value = $data[$field->name];
            foreach ($field->rules as $rule) {
                if ($rule->rule === 'required' && !array_key_exists($field->name, $data)) {
                    continue;
                }
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
