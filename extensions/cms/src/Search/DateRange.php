<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Search;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Date range for analytics queries.
 */
#[Api(since: '1.0.0')]
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}
}
