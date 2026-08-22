<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when a tenant-scoped write matches no row under the active tenant.
 *
 * With the tenant predicate applied, an UPDATE or DELETE that affects zero rows
 * for an entity the caller loaded is not an ordinary stale-entity condition: it
 * means the row belongs to another tenant (a cross-tenant write attempt) or was
 * removed. Surfacing it as an isolation failure — rather than a silent no-op —
 * stops a caller from believing a cross-tenant mutation succeeded.
 * @api
 */
#[Api(since: '1.0.0')]
final class TenantIsolationException extends OrmException
{
    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function writeMatchedNoTenantRow(string $entityClass, string $operation, string|int $id): self
    {
        return new self(sprintf(
            'Tenant isolation: %s of %s#%s matched no row for the active tenant '
            . '(the row belongs to another tenant or no longer exists)',
            $operation,
            $entityClass,
            $id,
        ));
    }
}
