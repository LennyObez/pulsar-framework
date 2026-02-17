<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\FunnelDefinition;
use Pulsar\Extension\Analytics\Domain\FunnelResult;
use Pulsar\Extension\Analytics\Domain\FunnelStep;

/**
 * Multi-step conversion funnel analysis.
 */
#[Api(since: '1.0.0')]
interface FunnelServiceInterface
{
    /**
     * Create a new funnel definition.
     *
     * @param list<FunnelStep> $steps
     */
    public function create(string $siteId, string $name, array $steps): FunnelDefinition;

    /**
     * Get a funnel definition by ID.
     */
    public function findById(string $id): ?FunnelDefinition;

    /**
     * List all funnels for a site.
     *
     * @return list<FunnelDefinition>
     */
    public function listForSite(string $siteId): array;

    /**
     * Delete a funnel definition.
     */
    public function delete(string $id): void;

    /**
     * Evaluate a funnel over a date range.
     */
    public function evaluate(
        string $funnelId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): FunnelResult;
}
