<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture\Attributes;

use Override;

use function strtoupper;

/**
 * A genuine override, annotated as one.
 *
 * The predicate must answer true for `handle` and false for `alsoOnlyHere`: same class,
 * same visibility, and the only difference between them is the thing the rule claims to
 * be checking.
 */
final class HandlesRequestsDifferently extends HandlesRequests
{
    #[Override]
    public function handle(string $request): string
    {
        return strtoupper($request);
    }

    public function alsoOnlyHere(): string
    {
        return 'nothing above this declares me either';
    }
}
