<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Supported entity relation types.
 */
#[Api(since: '1.0.0')]
enum RelationType: string
{
    case BelongsTo = 'belongs_to';
    case HasOne = 'has_one';
    case HasMany = 'has_many';
    case BelongsToMany = 'belongs_to_many';
}
