<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Security;

use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Domain\AdminPermission;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;

/**
 * Central access gate for admin operations.
 *
 * Wraps the ABAC PolicyInterface to provide admin-specific access checks
 * with structured exceptions.
 */
#[Internal]
final readonly class AdminAccessGate
{
    public function __construct(
        private PolicyInterface $policy,
    ) {}

    /**
     * Assert the identity can access the admin panel.
     *
     * @throws AdminAccessDeniedException
     */
    public function assertCanAccess(IdentityInterface $identity): void
    {
        $result = $this->policy->evaluate(
            $identity,
            new PolicyContext(permission: AdminPermission::AccessPanel->value),
        );

        if ($result !== true) {
            throw AdminAccessDeniedException::insufficientRole('admin');
        }
    }

    /**
     * Assert the identity can perform an operation on a resource.
     *
     * @throws AdminAccessDeniedException
     */
    public function assertCanPerform(
        IdentityInterface $identity,
        string $resourceName,
        ResourceOperation $operation,
    ): void {
        $permission = match ($operation) {
            ResourceOperation::List,
            ResourceOperation::View,
            ResourceOperation::Create,
            ResourceOperation::Update,
            ResourceOperation::Delete,
            ResourceOperation::BulkAction => AdminPermission::ManageResources,
            ResourceOperation::Export => AdminPermission::ExportData,
        };

        $result = $this->policy->evaluate(
            $identity,
            new PolicyContext(
                permission: $permission->value,
                resource: $resourceName,
                attributes: ['operation' => $operation->value],
            ),
        );

        if ($result !== true) {
            throw AdminAccessDeniedException::operationDenied($resourceName, $operation);
        }
    }
}
