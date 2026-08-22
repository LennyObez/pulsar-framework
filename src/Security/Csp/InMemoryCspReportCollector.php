<?php

declare(strict_types=1);

namespace Pulsar\Security\Csp;

use Override;
use Pulsar\Api\Api;

/**
 * In-memory CSP report collector for testing and lightweight deployments.
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemoryCspReportCollector implements CspReportCollectorInterface
{
    /** @var list<CspViolationReport> */
    private array $reports = [];

    #[Override]
    public function collect(CspViolationReport $report): void
    {
        $this->reports[] = $report;
    }

    #[Override]
    public function aggregateByDirective(): array
    {
        $counts = [];

        foreach ($this->reports as $report) {
            $directive = $report->effectiveDirective;

            if ($directive === '') {
                continue;
            }

            $counts[$directive] = ($counts[$directive] ?? 0) + 1;
        }

        return $counts;
    }

    #[Override]
    public function aggregateByBlockedUri(): array
    {
        $counts = [];

        foreach ($this->reports as $report) {
            $uri = $report->blockedUri;

            if ($uri === '') {
                continue;
            }

            $counts[$uri] = ($counts[$uri] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<CspViolationReport>
     */
    public function all(): array
    {
        return $this->reports;
    }
}
