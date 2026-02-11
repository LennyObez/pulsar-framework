<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a property as the optimistic lock version column.
 *
 * The column is automatically incremented on each update and used
 * to detect concurrent modification conflicts.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Version {}
