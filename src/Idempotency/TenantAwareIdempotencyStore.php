<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Tenancy\TenantContext;

use function sprintf;

/**
 * Decorator that prefixes every idempotency key with the active tenant id.
 *
 * Without this layer, two tenants who chose the same logical idempotency
 * key collide on the same row in the underlying store. Tenant A's
 * `'POST /charges 17'` and tenant B's `'POST /charges 17'` would either
 * be served the same cached result (cross-tenant data exposure) or one
 * tenant's call would be silently rejected as a parameter mismatch when
 * the parameters hash differs.
 *
 * The decorator owns no state — every operation forwards to an underlying
 * `IdempotencyStoreInterface` after rewriting the key. The tenant
 * identifier is read from the active `TenantContext` (per-Fiber).
 * If no tenant is currently resolved the keys flow through unchanged so
 * single-tenant deployments and bootstrap-time calls still work.
 *
 * The namespace token uses a NUL separator (`tenant:<id>:0:`). NUL is
 * outside the printable-ASCII grammar enforced by callers' own key
 * validators (`/^[\x21-\x7E]{1,256}$/` for the payments gateway), so a
 * raw caller key can never collide with an injected namespace prefix
 * regardless of what the tenant chooses to put in their key.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TenantAwareIdempotencyStore implements IdempotencyStoreInterface
{
    /**
     * NUL byte segment separator. Forbidden by the payments gateway's
     * `IDEMPOTENCY_KEY_PATTERN` (printable-ASCII only) so a tenant cannot
     * forge a key that masquerades as another tenant's namespaced key.
     */
    private const string NAMESPACE_SEPARATOR = "\0";

    public function __construct(
        private IdempotencyStoreInterface $inner,
        private TenantContext $tenantContext,
    ) {}

    #[Override]
    public function claim(
        string $key,
        string $parametersHash,
        string $operation,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): IdempotencyClaim {
        return $this->inner->claim(
            $this->namespacedKey($key),
            $parametersHash,
            $operation,
            $now,
            $ttlSeconds,
        );
    }

    #[Override]
    public function commit(string $key, string $resultPayload): void
    {
        $this->inner->commit($this->namespacedKey($key), $resultPayload);
    }

    #[Override]
    public function release(string $key): void
    {
        $this->inner->release($this->namespacedKey($key));
    }

    #[Override]
    public function prune(DateTimeImmutable $before): int
    {
        // Pruning is store-wide and not key-scoped — forward as-is.
        return $this->inner->prune($before);
    }

    private function namespacedKey(string $key): string
    {
        $tenant = $this->tenantContext->tryGet();

        if ($tenant === null) {
            // No active tenant (bootstrap, CLI command, single-tenant
            // deployment) — pass the key through untouched so the
            // underlying store sees the same shape it always did.
            return $key;
        }

        return sprintf(
            'tenant%s%s%s%s',
            self::NAMESPACE_SEPARATOR,
            $tenant->id,
            self::NAMESPACE_SEPARATOR,
            $key,
        );
    }
}
