<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when an optimistic lock conflict is detected (version mismatch).
 * @api
 */
#[Api(since: '1.0.0')]
final class OptimisticLockException extends OrmException
{
    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function versionMismatch(string $entityClass, string|int $id, int $expected, int $actual): self
    {
        return new self(sprintf(
            'Optimistic lock failure for %s#%s: expected version %d, found %d',
            $entityClass,
            $id,
            $expected,
            $actual,
        ));
    }

    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function staleEntity(string $entityClass, string|int $id): self
    {
        return new self(sprintf(
            'Entity %s#%s has been modified by another process',
            $entityClass,
            $id,
        ));
    }
}
