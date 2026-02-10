<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Pulsar\Api\Internal;

/**
 * Result of an environment guard check for REPL access.
 */
#[Internal]
final readonly class GuardResult
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public bool $isProductionOverride = false,
    ) {}
}
