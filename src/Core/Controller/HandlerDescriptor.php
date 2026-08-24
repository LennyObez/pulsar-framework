<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Pulsar\Api\Internal;

/**
 * Everything the kernel reflects about one handler, memoised per process.
 *
 * Replaces the three parallel caches (handlerWantsRequest, handlerUsesArrayParams,
 * handlerParamMap) with one entry built from ONE ReflectionMethod instead of two.
 */
#[Internal(reason: 'Kernel invocation cache')]
final readonly class HandlerDescriptor
{
    public function __construct(
        public bool $wantsRequest,
        public bool $usesArrayParams,
        public HandlerSignature $signature,
    ) {}
}
