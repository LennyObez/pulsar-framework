<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Typed entity identifier value object.
 *
 * Carries the entity class name and primary key value for
 * identity map lookups and audit trail references.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EntityId
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        public string $entityClass,
        public string|int $value,
    ) {}

    #[NoDiscard]
    public function toString(): string
    {
        return sprintf('%s#%s', $this->entityClass, $this->value);
    }

    public function equals(self $other): bool
    {
        return $this->entityClass === $other->entityClass
            && $this->value === $other->value;
    }
}
