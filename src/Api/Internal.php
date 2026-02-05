<?php

declare(strict_types=1);

namespace Pulsar\Api;

use Attribute;

/**
 * Marks a class, method, or class constant as explicitly internal.
 *
 * This attribute is optional — everything without #[Api] is internal by default.
 * Use this for emphasis on classes that users might mistakenly depend on
 * (e.g., types exposed via public readonly properties that are implementation details).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT)]
#[Api]
final readonly class Internal
{
    public function __construct(
        public string $reason = '',
    ) {}
}
