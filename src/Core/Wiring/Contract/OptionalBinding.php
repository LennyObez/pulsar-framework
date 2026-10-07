<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring\Contract;

use Pulsar\Api\Api;

/**
 * An optional container binding a wiring uses, and the feature it gates.
 *
 * When the binding is absent at boot the wiring still works, but the named
 * feature is silently disabled. Declaring it lets diagnostics surface the
 * degradation (with a fix) instead of leaving it to a buried log line.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class OptionalBinding
{
    /**
     * @param class-string|string $binding  The container id this feature needs
     * @param string              $feature  Human-readable feature it enables
     * @param string              $fix      How an operator restores it (which wiring must provide the binding)
     * @param bool                $security Whether the gated feature is security-relevant (escalates the diagnostic)
     */
    public function __construct(
        public string $binding,
        public string $feature,
        public string $fix,
        public bool $security = false,
    ) {}
}
