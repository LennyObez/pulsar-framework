<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when entity mapping metadata is invalid or incomplete.
 */
#[Api(since: '1.0.0')]
final class MappingException extends OrmException
{
    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function missingTable(string $entityClass): self
    {
        return new self(sprintf(
            'Entity %s is missing a #[Table] attribute',
            $entityClass,
        ));
    }

    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function missingId(string $entityClass): self
    {
        return new self(sprintf(
            'Entity %s has no property with a #[Id] attribute',
            $entityClass,
        ));
    }

    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function duplicateColumn(string $entityClass, string $column): self
    {
        return new self(sprintf(
            'Entity %s has duplicate column mapping for "%s"',
            $entityClass,
            $column,
        ));
    }

    /**
     * @param class-string $entityClass
     */
    #[NoDiscard]
    public static function invalidRelation(string $entityClass, string $property, string $reason): self
    {
        return new self(sprintf(
            'Invalid relation on %s::$%s: %s',
            $entityClass,
            $property,
            $reason,
        ));
    }

    #[NoDiscard]
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
