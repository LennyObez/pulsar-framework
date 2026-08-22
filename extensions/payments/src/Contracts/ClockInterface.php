<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Testable time abstraction.
 * @api
 */
#[Api(since: '1.0.0')]
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
