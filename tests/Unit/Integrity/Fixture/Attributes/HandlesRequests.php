<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture\Attributes;

/**
 * A base with something to override, so OverrideScanner's predicate has a true case to
 * answer as well as a false one.
 */
class HandlesRequests
{
    public function handle(string $request): string
    {
        return $request;
    }

    public function onlyHere(): string
    {
        return 'nothing above this declares me';
    }
}
