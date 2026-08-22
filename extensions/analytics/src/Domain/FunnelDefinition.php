<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A multi-step conversion funnel definition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FunnelDefinition
{
    /**
     * @param string $id Unique funnel identifier
     * @param string $siteId Site this funnel belongs to
     * @param string $name Human-readable funnel name
     * @param list<FunnelStep> $steps Ordered funnel steps
     */
    public function __construct(
        public string $id,
        public string $siteId,
        public string $name,
        public array $steps,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
