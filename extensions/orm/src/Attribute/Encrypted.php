<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a column as encrypted at rest.
 *
 * Encrypted columns are transparently encrypted during dehydration (write)
 * and decrypted during hydration (read). They cannot be used in WHERE
 * or ORDER BY clauses unless a blind index is configured.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Encrypted {}
