<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Supported entity relation types.
 * @api
 */
#[Api(since: '1.0.0')]
enum RelationType: string
{
    case BelongsTo = 'belongs_to';
    case HasOne = 'has_one';
    case HasMany = 'has_many';
    case BelongsToMany = 'belongs_to_many';
    case MorphTo = 'morph_to';
    case MorphMany = 'morph_many';
    case HasManyThrough = 'has_many_through';
    case MorphToMany = 'morph_to_many';

    /**
     * Whether this relation type is polymorphic.
     */
    public function isPolymorphic(): bool
    {
        return match ($this) {
            self::MorphTo, self::MorphMany, self::MorphToMany => true,
            default => false,
        };
    }
}
