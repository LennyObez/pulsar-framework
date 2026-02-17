<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Contracts\FlowServiceInterface;
use Pulsar\Extension\Analytics\Domain\FlowStep;

use function array_slice;
use function round;

/**
 * Analyzes visitor navigation flows using session page-view sequences.
 */
#[Internal(reason: 'Flow analysis service; use FlowServiceInterface')]
final readonly class FlowService implements FlowServiceInterface
{
    private const string SQL_FLOW_PATHS = <<<'SQL'
        SELECT
            pv1.pathname AS source,
            pv2.pathname AS target,
            COUNT(DISTINCT pv1.visitor_id) AS visitors
        FROM analytics_page_views pv1
        INNER JOIN analytics_page_views pv2
            ON pv1.session_id = pv2.session_id
            AND pv1.site_id = pv2.site_id
            AND pv2.created_at > pv1.created_at
        WHERE pv1.site_id = :site_id
            AND pv1.created_at >= :from
            AND pv1.created_at <= :to
            AND pv1.pathname = :entry_page
        GROUP BY pv1.pathname, pv2.pathname
        ORDER BY visitors DESC
        LIMIT :limit
        SQL;

    private const string SQL_EXIT_PAGES = <<<'SQL'
        SELECT
            s.exit_page AS pathname,
            COUNT(*) AS exits,
            COUNT(*) * 100.0 / NULLIF(SUM(COUNT(*)) OVER(), 0) AS exit_rate
        FROM analytics_sessions s
        WHERE s.site_id = :site_id
            AND s.started_at >= :from
            AND s.started_at <= :to
        GROUP BY s.exit_page
        ORDER BY exits DESC
        LIMIT :limit
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getFlowFromPage(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $entryPage = '/',
        int $depth = 3,
        int $limit = 20,
    ): array {
        $result = $this->connection->query(self::SQL_FLOW_PATHS, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'entry_page' => $entryPage,
            'limit' => $limit,
        ]);

        $steps = [];
        $currentDepth = 0;

        foreach ($result->rows as $row) {
            $steps[] = new FlowStep(
                source: $row->getString('source'),
                target: $row->getString('target'),
                visitors: $row->getInt('visitors'),
                depth: $currentDepth,
            );
        }

        // Build deeper levels if depth > 1
        if ($depth > 1 && $steps !== []) {
            $nextPages = array_unique(array_map(
                static fn(FlowStep $s): string => $s->target,
                $steps,
            ));

            foreach (array_slice($nextPages, 0, 5) as $nextPage) {
                $deeperSteps = $this->getFlowFromPageAtDepth(
                    $siteId,
                    $from,
                    $to,
                    $nextPage,
                    $currentDepth + 1,
                    min($limit, 10),
                );

                $steps = [...$steps, ...$deeperSteps];
            }
        }

        return $steps;
    }

    #[Override]
    public function getExitPages(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 10,
    ): array {
        $result = $this->connection->query(self::SQL_EXIT_PAGES, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);

        $pages = [];

        foreach ($result->rows as $row) {
            $pages[] = [
                'pathname' => $row->getString('pathname'),
                'exits' => $row->getInt('exits'),
                'exit_rate' => round($row->getFloat('exit_rate'), 1),
            ];
        }

        return $pages;
    }

    /**
     * @return list<FlowStep>
     */
    private function getFlowFromPageAtDepth(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $sourcePage,
        int $depth,
        int $limit,
    ): array {
        $result = $this->connection->query(self::SQL_FLOW_PATHS, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'entry_page' => $sourcePage,
            'limit' => $limit,
        ]);

        $steps = [];

        foreach ($result->rows as $row) {
            $steps[] = new FlowStep(
                source: $row->getString('source'),
                target: $row->getString('target'),
                visitors: $row->getInt('visitors'),
                depth: $depth,
            );
        }

        return $steps;
    }
}
