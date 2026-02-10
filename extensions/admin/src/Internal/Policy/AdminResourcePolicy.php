<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Policy;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Domain\AdminPermission;

/**
 * ABAC policy for admin resource operations.
 *
 * Evaluates whether the current identity has the required admin role
 * and specific permission for the requested operation.
 */
#[Internal]
final readonly class AdminResourcePolicy implements PolicyInterface
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {}

    #[Override]
    public function evaluate(IdentityInterface $identity, PolicyContext $context): ?bool
    {
        if (!str_starts_with($context->permission, 'admin.')) {
            return null;
        }

        if (!$identity->isAuthenticated()) {
            return false;
        }

        if (!$identity->hasRole($this->config->security->requiredRole)) {
            return false;
        }

        $permission = AdminPermission::tryFrom($context->permission);
        if ($permission === null) {
            return null;
        }

        return match ($permission) {
            AdminPermission::AccessPanel,
            AdminPermission::ViewDashboard => true,
            AdminPermission::ManageResources,
            AdminPermission::ExportData,
            AdminPermission::ViewAuditLog => $identity->hasRole('admin'),
            AdminPermission::ManageSettings => $identity->hasRole('super_admin'),
            AdminPermission::SchemaView,
            AdminPermission::SchemaCreate,
            AdminPermission::SchemaAlter,
            AdminPermission::SchemaDrop,
            AdminPermission::SchemaRename => null,
        };
    }
}
