<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Resource;

/**
 * Backed enum used by the admin field and filter tests as a sample enum type.
 *
 * Deliberately in its own PSR-4 file rather than beside one of its consumers: a
 * helper declared inside another test file is only reachable once that file
 * happens to have been loaded, which holds under sequential execution and breaks
 * as soon as tests run in separate parallel workers.
 *
 * @internal
 */
enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}
