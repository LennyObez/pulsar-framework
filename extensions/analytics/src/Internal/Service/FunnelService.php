<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Contracts\FunnelServiceInterface;
use Pulsar\Extension\Analytics\Domain\FunnelDefinition;
use Pulsar\Extension\Analytics\Domain\FunnelResult;
use Pulsar\Extension\Analytics\Domain\FunnelStep;
use Pulsar\Extension\Analytics\Domain\FunnelStepResult;
use Pulsar\Extension\Analytics\Domain\FunnelStepType;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;

use function array_map;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function round;
use function str_replace;

use const JSON_THROW_ON_ERROR;

/**
 * Multi-step conversion funnel analysis.
 */
#[Internal(reason: 'Funnel service; use FunnelServiceInterface')]
final readonly class FunnelService implements FunnelServiceInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function create(string $siteId, string $name, array $steps): FunnelDefinition
    {
        $id = bin2hex(random_bytes(18));
        $now = new DateTimeImmutable();

        $stepsJson = json_encode(
            array_map(static fn(FunnelStep $s): array => [
                'position' => $s->position,
                'name' => $s->name,
                'type' => $s->type->value,
                'value' => $s->value,
            ], $steps),
            JSON_THROW_ON_ERROR,
        );

        $this->connection->execute(
            'INSERT INTO analytics_funnels (id, site_id, name, steps, created_at) VALUES (:id, :site_id, :name, :steps, :created_at)',
            [
                'id' => $id,
                'site_id' => $siteId,
                'name' => $name,
                'steps' => $stepsJson,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        return new FunnelDefinition(
            id: $id,
            siteId: $siteId,
            name: $name,
            steps: $steps,
            createdAt: $now,
        );
    }

    #[Override]
    public function findById(string $id): ?FunnelDefinition
    {
        $row = $this->connection->query(
            'SELECT * FROM analytics_funnels WHERE id = :id',
            ['id' => $id],
        )->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    #[Override]
    public function listForSite(string $siteId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM analytics_funnels WHERE site_id = :site_id ORDER BY created_at DESC',
            ['site_id' => $siteId],
        );

        $funnels = [];

        foreach ($result->rows as $row) {
            $funnels[] = $this->hydrate($row);
        }

        return $funnels;
    }

    #[Override]
    public function delete(string $id): void
    {
        $affected = $this->connection->execute(
            'DELETE FROM analytics_funnels WHERE id = :id',
            ['id' => $id],
        );

        if ($affected === 0) {
            throw AnalyticsException::notFound('Funnel', $id);
        }
    }

    #[Override]
    public function evaluate(string $funnelId, DateTimeImmutable $from, DateTimeImmutable $to): FunnelResult
    {
        $funnel = $this->findById($funnelId);

        if ($funnel === null) {
            throw AnalyticsException::notFound('Funnel', $funnelId);
        }

        if ($funnel->steps === []) {
            return new FunnelResult(
                funnelId: $funnelId,
                steps: [],
                overallConversionRate: 0.0,
            );
        }

        // Sequential funnel: each step narrows the visitor set to only
        // those who completed the previous step BEFORE completing this one.
        $eligibleVisitorIds = $this->getStepVisitorIds($funnel->siteId, $from, $to, $funnel->steps[0]);
        $stepResults = [];
        $previousVisitors = null;

        foreach ($funnel->steps as $i => $step) {
            if ($i === 0) {
                $currentVisitorIds = $eligibleVisitorIds;
            } else {
                // Intersect with previous eligible set AND enforce time ordering
                $currentVisitorIds = $this->getSequentialStepVisitorIds(
                    $funnel->siteId,
                    $from,
                    $to,
                    $funnel->steps[$i - 1],
                    $step,
                    $eligibleVisitorIds,
                );
            }

            $visitors = count($currentVisitorIds);

            $dropOffRate = $previousVisitors !== null && $previousVisitors > 0
                ? round((1 - $visitors / (float) $previousVisitors) * 100, 1)
                : 0.0;

            $conversionRate = $previousVisitors !== null && $previousVisitors > 0
                ? round(($visitors / (float) $previousVisitors) * 100, 1)
                : 100.0;

            $stepResults[] = new FunnelStepResult(
                position: $step->position,
                name: $step->name,
                visitors: $visitors,
                dropOffRate: $dropOffRate,
                conversionRate: $conversionRate,
            );

            $previousVisitors = $visitors;
            $eligibleVisitorIds = $currentVisitorIds;
        }

        $firstStepVisitors = $stepResults[0]->visitors;
        $lastStepVisitors = $stepResults[count($stepResults) - 1]->visitors;
        $overallRate = $firstStepVisitors > 0
            ? round(($lastStepVisitors / (float) $firstStepVisitors) * 100, 1)
            : 0.0;

        return new FunnelResult(
            funnelId: $funnelId,
            steps: $stepResults,
            overallConversionRate: $overallRate,
        );
    }

    /**
     * Get the set of distinct visitor IDs who performed a given step.
     *
     * @return list<string>
     */
    private function getStepVisitorIds(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        FunnelStep $step,
    ): array {
        if ($step->type === FunnelStepType::PageVisit) {
            $result = $this->connection->query(
                'SELECT DISTINCT visitor_id FROM analytics_page_views WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to AND pathname LIKE :pathname',
                [
                    'site_id' => $siteId,
                    'from' => $from->format('Y-m-d H:i:s'),
                    'to' => $to->format('Y-m-d H:i:s'),
                    'pathname' => str_replace('*', '%', $step->value),
                ],
            );
        } else {
            $result = $this->connection->query(
                'SELECT DISTINCT visitor_id FROM analytics_events WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to AND event_name = :event_name',
                [
                    'site_id' => $siteId,
                    'from' => $from->format('Y-m-d H:i:s'),
                    'to' => $to->format('Y-m-d H:i:s'),
                    'event_name' => $step->value,
                ],
            );
        }

        $ids = [];

        foreach ($result->rows as $row) {
            $ids[] = $row->getString('visitor_id');
        }

        return $ids;
    }

    /**
     * Get visitors who completed prevStep and then currentStep in chronological order.
     *
     * Only visitors in the eligible set are considered. The current step must
     * have a timestamp strictly after the previous step's earliest timestamp.
     *
     * @param list<string> $eligibleVisitorIds
     * @return list<string>
     */
    private function getSequentialStepVisitorIds(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        FunnelStep $prevStep,
        FunnelStep $currentStep,
        array $eligibleVisitorIds,
    ): array {
        if ($eligibleVisitorIds === []) {
            return [];
        }

        // Build the sequential query: find visitors in the eligible set who
        // performed currentStep AFTER performing prevStep
        $prevTable = $prevStep->type === FunnelStepType::PageVisit
            ? 'analytics_page_views'
            : 'analytics_events';
        $prevCondition = $prevStep->type === FunnelStepType::PageVisit
            ? 'pathname LIKE :prev_value'
            : 'event_name = :prev_value';

        $currTable = $currentStep->type === FunnelStepType::PageVisit
            ? 'analytics_page_views'
            : 'analytics_events';
        $currCondition = $currentStep->type === FunnelStepType::PageVisit
            ? 'pathname LIKE :curr_value'
            : 'event_name = :curr_value';

        // Use placeholders for eligible visitor IDs
        $placeholders = [];
        $params = [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'prev_value' => $prevStep->type === FunnelStepType::PageVisit
                ? str_replace('*', '%', $prevStep->value)
                : $prevStep->value,
            'curr_value' => $currentStep->type === FunnelStepType::PageVisit
                ? str_replace('*', '%', $currentStep->value)
                : $currentStep->value,
        ];

        foreach ($eligibleVisitorIds as $idx => $vid) {
            $key = 'vid_' . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $vid;
        }

        $inClause = implode(', ', $placeholders);

        $sql = <<<SQL
            SELECT DISTINCT curr.visitor_id
            FROM {$currTable} curr
            INNER JOIN {$prevTable} prev
                ON prev.visitor_id = curr.visitor_id
                AND prev.site_id = curr.site_id
                AND prev.created_at < curr.created_at
            WHERE curr.site_id = :site_id
                AND curr.created_at >= :from
                AND curr.created_at <= :to
                AND curr.{$currCondition}
                AND prev.created_at >= :from
                AND prev.created_at <= :to
                AND prev.{$prevCondition}
                AND curr.visitor_id IN ({$inClause})
            SQL;

        $result = $this->connection->query($sql, $params);
        $ids = [];

        foreach ($result->rows as $row) {
            $ids[] = $row->getString('visitor_id');
        }

        return $ids;
    }

    /**
     * @param \Pulsar\Database\Row $row
     */
    private function hydrate(mixed $row): FunnelDefinition
    {
        /** @var list<array{position: int, name: string, type: string, value: string}> $stepsData */
        $stepsData = json_decode($row->getString('steps'), true, flags: JSON_THROW_ON_ERROR);

        $steps = array_map(
            static fn(array $s): FunnelStep => new FunnelStep(
                position: $s['position'],
                name: $s['name'],
                type: FunnelStepType::from($s['type']),
                value: $s['value'],
            ),
            $stepsData,
        );

        return new FunnelDefinition(
            id: $row->getString('id'),
            siteId: $row->getString('site_id'),
            name: $row->getString('name'),
            steps: $steps,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
