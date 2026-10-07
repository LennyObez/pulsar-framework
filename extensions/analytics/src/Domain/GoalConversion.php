<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A recorded goal conversion.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GoalConversion
{
    public function __construct(
        public string $id,
        public string $goalId,
        public string $siteId,
        public string $visitorId,
        public string $sessionId,
        public ?float $revenueValue = null,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
