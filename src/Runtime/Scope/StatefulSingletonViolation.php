<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Scope;

use Pulsar\Api\Internal;

#[Internal]
final readonly class StatefulSingletonViolation
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $className,
        public string $property,
        public ViolationType $type,
        public string $message,
    ) {}
}
