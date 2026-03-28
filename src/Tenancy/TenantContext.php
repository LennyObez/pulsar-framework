<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use Fiber;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Runtime\ResettableInterface;
use Pulsar\Tenancy\Exception\TenancyException;
use stdClass;
use WeakMap;

/**
 * Holds the current tenant for the active request, isolated per Fiber.
 *
 * Persistent-runtime workers (RoadRunner, FrankenPHP, Swoole) interleave
 * Fiber-suspended HTTP requests on the same worker process. A naive
 * `private ?Tenant $tenant` field was shared across every Fiber on the
 * worker, so request A could read or overwrite request B's tenant —
 * a hard cross-tenant isolation breach (F13.1).
 *
 * Storage is keyed by `Fiber::getCurrent()` — or a stable `$rootKey` for
 * code running outside any Fiber — using a `WeakMap`. When a Fiber
 * completes and is garbage-collected its slot is reclaimed automatically.
 */
#[Api(since: '1.0.0')]
final class TenantContext implements ResettableInterface
{
    /** @var WeakMap<object, Tenant> Per-Fiber (or root) tenant. */
    private WeakMap $tenants;

    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, Tenant> $map */
        $map = new WeakMap();
        $this->tenants = $map;
        $this->rootKey = new stdClass();
    }

    /**
     * Set the current Fiber's tenant.
     */
    public function set(Tenant $tenant): void
    {
        $this->tenants[$this->currentKey()] = $tenant;
    }

    /**
     * Get the current Fiber's tenant.
     *
     * @throws TenancyException If no tenant has been resolved.
     */
    #[NoDiscard]
    public function get(): Tenant
    {
        return $this->tryGet() ?? throw TenancyException::tenantNotResolved();
    }

    /**
     * Get the current Fiber's tenant without throwing.
     */
    public function tryGet(): ?Tenant
    {
        return $this->tenants[$this->currentKey()] ?? null;
    }

    /**
     * Check if a tenant has been resolved for the current Fiber.
     */
    public function isResolved(): bool
    {
        return isset($this->tenants[$this->currentKey()]);
    }

    /**
     * Clear the current Fiber's tenant.
     */
    public function clear(): void
    {
        unset($this->tenants[$this->currentKey()]);
    }

    #[Override]
    public function resetRequestState(): void
    {
        $this->clear();
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
