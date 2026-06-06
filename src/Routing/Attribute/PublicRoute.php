<?php

declare(strict_types=1);

namespace Pulsar\Routing\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a route handler as intentionally public (no authorization required).
 *
 * In regulated presets (banking, healthcare, legal), model binding normally
 * mandates authorization on every resolved model. This attribute explicitly
 * opts a route out of that requirement, and the reason field documents
 * the justification for security audit trails.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0-rc.11')]
final readonly class PublicRoute
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $reason = '',
    ) {}
}
