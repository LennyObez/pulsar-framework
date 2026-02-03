<?php

declare(strict_types=1);

namespace Pulsar\Api;

use Attribute;

/**
 * Marks a class, method, or class constant as part of the public API.
 *
 * Types marked with this attribute are covered by semantic versioning guarantees.
 * Breaking changes to these types require a major version bump.
 *
 * Everything without this attribute is internal by default and may change
 * between minor versions without notice.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT)]
#[Api]
final readonly class Api
{
    public function __construct(
        public string $since = '',
    ) {}
}
