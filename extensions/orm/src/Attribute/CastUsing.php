<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Specifies a custom caster class for a column value.
 *
 * The caster class must implement two static methods:
 * - fromDatabase(mixed $value): mixed: convert DB value to PHP value
 * - toDatabase(mixed $value): mixed: convert PHP value to DB value
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class CastUsing
{
    /**
     * @param class-string $casterClass
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $casterClass,
    ) {}
}
