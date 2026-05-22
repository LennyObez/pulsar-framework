<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use Override;
use Pulsar\Config\TenancyConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Tenancy\Exception\TenancyException;

use function str_replace;

/**
 * Decorates a ConnectionManager to apply tenant-scoped database isolation.
 *
 * Supports prefix-based table naming and separate connection switching.
 */
final readonly class TenantAwareConnectionManager implements ConnectionManagerInterface
{
    public function __construct(
        private ConnectionManagerInterface $inner,
        private TenantContext $context,
        private TenancyConfig $config,
    ) {}

    #[Override]
    public function connection(?string $name = null): ConnectionInterface
    {
        $strategy = $this->config->database->strategy;

        if ($strategy === TenantDatabaseStrategy::SeparateConnection && $this->context->isResolved()) {
            $tenant = $this->context->get();
            $connectionName = 'tenant_' . $tenant->id;

            return $this->inner->connection($connectionName);
        }

        return $this->inner->connection($name);
    }

    #[Override]
    public function getDefaultConnectionName(): string
    {
        return $this->inner->getDefaultConnectionName();
    }

    #[Override]
    public function disconnect(?string $name = null): void
    {
        $this->inner->disconnect($name);
    }

    /**
     * Get the table prefix for the current tenant.
     *
     * @throws TenancyException If no tenant is resolved and prefix strategy is used.
     */
    public function getTablePrefix(): string
    {
        $strategy = $this->config->database->strategy;

        if ($strategy !== TenantDatabaseStrategy::Prefix) {
            return '';
        }

        if (!$this->context->isResolved()) {
            throw TenancyException::tenantNotResolved();
        }

        $tenant = $this->context->get();

        return str_replace('{tenant_id}', $tenant->id, $this->config->database->prefixTemplate);
    }
}
