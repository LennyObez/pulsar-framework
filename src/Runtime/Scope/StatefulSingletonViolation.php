<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Scope;

use Pulsar\Api\Internal;

#[Internal]
final readonly class StatefulSingletonViolation
{
    public function __construct(
        public string $className,
        public string $property,
        public ViolationType $type,
        public string $message,
    ) {}
}
