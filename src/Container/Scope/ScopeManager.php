<?php

declare(strict_types=1);

namespace Pulsar\Container\Scope;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Container\Lifetime;

/**
 * Manages scoped instance pools keyed by lifetime + service ID.
 *
 * Request and tenant scopes each maintain their own instance pool.
 * When a scope ends, all instances in that pool are evicted.
 * Tenant instances are keyed by tenant ID to prevent cross-tenant data leakage.
 */
#[Internal]
final class ScopeManager
{
    /** @var array<string, object> Request-scoped instances */
    private array $requestInstances = [];

    /** @var array<string, array<string, object>> Tenant-scoped instances keyed by tenant ID → service ID */
    private array $tenantInstances = [];

    private bool $requestScopeActive = false;
    private bool $tenantScopeActive = false;
    private ?string $currentTenantId = null;

    /**
     * Begin a scope for the given lifetime.
     *
     * Beginning a request scope evicts any stale instances from a previous scope.
     * Beginning a tenant scope switches to the specified tenant's instance pool.
     */
    public function beginScope(Lifetime $lifetime, ?string $tenantId = null): void
    {
        match ($lifetime) {
            Lifetime::RequestScope => (function (): void {
                $this->requestInstances = [];
                $this->requestScopeActive = true;
            })(),
            Lifetime::TenantScope => (function () use ($tenantId): void {
                $this->tenantScopeActive = true;
                $this->currentTenantId = $tenantId;
            })(),
            default => null,
        };
    }

    /**
     * End a scope, evicting all instances in that pool.
     */
    public function endScope(Lifetime $lifetime): void
    {
        match ($lifetime) {
            Lifetime::RequestScope => (function (): void {
                $this->requestInstances = [];
                $this->requestScopeActive = false;
            })(),
            Lifetime::TenantScope => (function (): void {
                if ($this->currentTenantId !== null) {
                    unset($this->tenantInstances[$this->currentTenantId]);
                }

                $this->tenantScopeActive = false;
                $this->currentTenantId = null;
            })(),
            default => null,
        };
    }

    /**
     * Check if a scope is currently active.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public function isActive(Lifetime $lifetime): bool
    {
        return match ($lifetime) {
            Lifetime::RequestScope => $this->requestScopeActive,
            Lifetime::TenantScope => $this->tenantScopeActive,
            default => true,
        };
    }

    /**
     * Get a cached scoped instance, or null if not yet resolved in this scope.
     */
    #[NoDiscard]
    public function getScopedInstance(string $id, Lifetime $lifetime): ?object
    {
        return match ($lifetime) {
            Lifetime::RequestScope => $this->requestInstances[$id] ?? null,
            Lifetime::TenantScope => $this->currentTenantId !== null
                ? ($this->tenantInstances[$this->currentTenantId][$id] ?? null)
                : null,
            default => null,
        };
    }

    /**
     * Cache an instance in the appropriate scope pool.
     */
    public function setScopedInstance(string $id, Lifetime $lifetime, object $instance): void
    {
        match ($lifetime) {
            Lifetime::RequestScope => $this->requestInstances[$id] = $instance,
            Lifetime::TenantScope => $this->currentTenantId !== null
                ? $this->tenantInstances[$this->currentTenantId][$id] = $instance
                : null,
            default => null,
        };
    }

    /**
     * Get the current tenant ID (if tenant scope is active).
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public function currentTenantId(): ?string
    {
        return $this->currentTenantId;
    }
}
