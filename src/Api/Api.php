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
 *
 * `$since` has no default. It used to default to the empty string, and exactly
 * one type in the tree — `AntiSpamConfigSet` — was marked `#[Api]` with nothing
 * else, which put an entry with `"since": ""` in the public API snapshot and told
 * a consumer that the type became stable in no release at all. A marker whose
 * only required half can be omitted is a marker that will be omitted, so the
 * parameter is required: `#[Api]` with no arguments is now rejected by the static
 * analysers and by reflection, and
 * {@see \Pulsar\Tests\Unit\Api\ApiSinceIsAlwaysDeclaredTest} catches the one shape
 * a required parameter cannot — an explicitly empty `since: ''`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT)]
#[Api(since: '1.0.0')]
final readonly class Api
{
    public function __construct(
        public string $since,
        public string $stability = 'stable',
    ) {}
}
