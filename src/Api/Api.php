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
 *
 * Stability grades:
 * - "stable"      : BC guaranteed within the same major version
 * - "experimental": may change in minor releases, not yet locked
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT)]
#[Api(since: '1.0.0')]
final readonly class Api
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $since = '',
        public string $stability = 'stable',
    ) {}
}
