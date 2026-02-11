<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when an entity cannot be found by its identifier.
 */
#[Api(since: '1.0.0')]
final class EntityNotFoundException extends OrmException
{
    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function notFound(string $entityClass, string|int $id): self
    {
        return new self(sprintf(
            'Entity %s with ID "%s" not found',
            $entityClass,
            $id,
        ));
    }

    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function notFoundByCriteria(string $entityClass, string $criteria): self
    {
        return new self(sprintf(
            'Entity %s not found matching criteria: %s',
            $entityClass,
            $criteria,
        ));
    }
}
