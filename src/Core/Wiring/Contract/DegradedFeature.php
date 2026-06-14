<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring\Contract;

use Pulsar\Api\Api;

use function sprintf;

/**
 * A feature disabled at boot because an optional binding it needs is unbound.
 *
 * The detector emits one per missing optional binding, carrying the reason and
 * the fix so diagnostics/health can tell an operator exactly what to wire — the
 * signal that was missing when CacheWiring failed to bind TaggedCacheInterface
 * and three anti-spam features went silently inert.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class DegradedFeature
{
    public function __construct(
        public string $component,
        public string $feature,
        public string $missingBinding,
        public string $fix,
        public bool $security,
    ) {}

    /**
     * A single-line, operator-facing description.
     */
    public function describe(): string
    {
        return sprintf(
            '%s: %s DISABLED — %s missing. %s',
            $this->component,
            $this->feature,
            $this->missingBinding,
            $this->fix,
        );
    }
}
