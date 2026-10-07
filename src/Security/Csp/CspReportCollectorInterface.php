<?php

declare(strict_types=1);

namespace Pulsar\Security\Csp;

use Pulsar\Api\Api;

/**
 * Stores CSP violation reports for aggregation and analysis.
 * @api
 */
#[Api(since: '1.0.0')]
interface CspReportCollectorInterface
{
    /**
     * Store a violation report.
     */
    public function collect(CspViolationReport $report): void;

    /**
     * Return aggregated violation counts grouped by directive.
     *
     * @return array<string, int> directive => count
     */
    public function aggregateByDirective(): array;

    /**
     * Return aggregated violation counts grouped by blocked URI.
     *
     * @return array<string, int> blockedUri => count
     */
    public function aggregateByBlockedUri(): array;
}
