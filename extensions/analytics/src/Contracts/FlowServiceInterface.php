<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\FlowStep;

/**
 * Analyzes visitor navigation flows (behavior flow / user flow).
 * @api
 */
#[Api(since: '1.0.0')]
interface FlowServiceInterface
{
    /**
     * Get navigation flow paths from a starting page.
     *
     * @param int $depth How many steps deep to analyze
     * @return list<FlowStep>
     */
    public function getFlowFromPage(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $entryPage = '/',
        int $depth = 3,
        int $limit = 20,
    ): array;

    /**
     * Get top exit pages with exit rates.
     *
     * @return list<array{pathname: string, exits: int, exit_rate: float}>
     */
    public function getExitPages(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 10,
    ): array;
}
