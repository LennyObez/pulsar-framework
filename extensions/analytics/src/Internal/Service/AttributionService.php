<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Contracts\AttributionServiceInterface;
use Pulsar\Extension\Analytics\Domain\AttributionModel;
use Pulsar\Extension\Analytics\Domain\AttributionResult;

use function count;
use function max;
use function round;
use function strtotime;
use function usort;

/**
 * Attribution modeling using per-visitor multi-touchpoint journeys.
 *
 * Each model operates on actual visitor touchpoint sequences rather than
 * fixed-weight approximations. Touchpoints are sourced from session
 * referrer data, ordered chronologically per visitor.
 */
#[Internal(reason: 'Attribution service; use AttributionServiceInterface')]
final readonly class AttributionService implements AttributionServiceInterface
{
    /**
     * Fetch per-visitor touchpoint journeys: each row is one session with
     * a non-empty referrer source, ordered by time per visitor.
     */
    private const string SQL_VISITOR_TOUCHPOINTS = <<<'SQL'
        SELECT
            s.visitor_id,
            pv.referrer_source AS source,
            s.started_at,
            COALESCE(gc.revenue, 0) AS revenue
        FROM analytics_sessions s
        INNER JOIN analytics_page_views pv
            ON pv.session_id = s.session_id AND pv.site_id = s.site_id
        LEFT JOIN analytics_goal_conversions gc
            ON gc.visitor_id = s.visitor_id AND gc.site_id = s.site_id
            AND gc.converted_at >= :from AND gc.converted_at <= :to
        WHERE s.site_id = :site_id
            AND s.started_at >= :from
            AND s.started_at <= :to
            AND pv.referrer_source != ''
        ORDER BY s.visitor_id, s.started_at ASC
        SQL;

    /**
     * Same query but filtered to a specific goal.
     */
    private const string SQL_VISITOR_TOUCHPOINTS_WITH_GOAL = <<<'SQL'
        SELECT
            s.visitor_id,
            pv.referrer_source AS source,
            s.started_at,
            COALESCE(gc.revenue, 0) AS revenue
        FROM analytics_sessions s
        INNER JOIN analytics_page_views pv
            ON pv.session_id = s.session_id AND pv.site_id = s.site_id
        INNER JOIN analytics_goal_conversions gc
            ON gc.visitor_id = s.visitor_id AND gc.site_id = s.site_id
            AND gc.converted_at >= :from AND gc.converted_at <= :to
            AND gc.goal_id = :goal_id
        WHERE s.site_id = :site_id
            AND s.started_at >= :from
            AND s.started_at <= :to
            AND pv.referrer_source != ''
        ORDER BY s.visitor_id, s.started_at ASC
        SQL;

    /**
     * Half-life for time-decay model in seconds (7 days).
     */
    private const int TIME_DECAY_HALF_LIFE = 604800;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function calculate(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        AttributionModel $model,
        ?string $goalId = null,
    ): array {
        $journeys = $this->loadVisitorJourneys($siteId, $from, $to, $goalId);

        return match ($model) {
            AttributionModel::FirstTouch => $this->applyFirstTouch($journeys),
            AttributionModel::LastTouch => $this->applyLastTouch($journeys),
            AttributionModel::Linear => $this->applyLinear($journeys),
            AttributionModel::TimeDecay => $this->applyTimeDecay($journeys),
        };
    }

    #[Override]
    public function compareModels(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $goalId = null,
    ): array {
        $journeys = $this->loadVisitorJourneys($siteId, $from, $to, $goalId);

        return [
            AttributionModel::FirstTouch->value => $this->applyFirstTouch($journeys),
            AttributionModel::LastTouch->value => $this->applyLastTouch($journeys),
            AttributionModel::Linear->value => $this->applyLinear($journeys),
            AttributionModel::TimeDecay->value => $this->applyTimeDecay($journeys),
        ];
    }

    /**
     * Load per-visitor touchpoint journeys from the database.
     *
     * @return array<string, list<array{source: string, started_at: string, revenue: float}>>
     */
    private function loadVisitorJourneys(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $goalId,
    ): array {
        $params = [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($goalId !== null) {
            $params['goal_id'] = $goalId;
            $sql = self::SQL_VISITOR_TOUCHPOINTS_WITH_GOAL;
        } else {
            $sql = self::SQL_VISITOR_TOUCHPOINTS;
        }

        $result = $this->connection->query($sql, $params);

        /** @var array<string, list<array{source: string, started_at: string, revenue: float}>> $journeys */
        $journeys = [];

        foreach ($result->rows as $row) {
            $visitorId = $row->getString('visitor_id');
            $journeys[$visitorId][] = [
                'source' => $row->getString('source'),
                'started_at' => $row->getString('started_at'),
                'revenue' => $row->getFloat('revenue'),
            ];
        }

        return $journeys;
    }

    /**
     * First-touch: 100% credit to the first touchpoint per visitor.
     *
     * @param array<string, list<array{source: string, started_at: string, revenue: float}>> $journeys
     * @return list<AttributionResult>
     */
    private function applyFirstTouch(array $journeys): array
    {
        /** @var array<string, array{conversions: int, revenue: float}> $credits */
        $credits = [];

        foreach ($journeys as $touchpoints) {
            if ($touchpoints === []) {
                continue;
            }

            $first = $touchpoints[0];
            $source = $first['source'];
            $totalRevenue = $this->sumRevenue($touchpoints);

            $credits[$source] ??= ['conversions' => 0, 'revenue' => 0.0];
            $credits[$source]['conversions']++;
            $credits[$source]['revenue'] += $totalRevenue;
        }

        return $this->buildResults($credits, 1.0);
    }

    /**
     * Last-touch: 100% credit to the last touchpoint per visitor.
     *
     * @param array<string, list<array{source: string, started_at: string, revenue: float}>> $journeys
     * @return list<AttributionResult>
     */
    private function applyLastTouch(array $journeys): array
    {
        /** @var array<string, array{conversions: int, revenue: float}> $credits */
        $credits = [];

        foreach ($journeys as $touchpoints) {
            if ($touchpoints === []) {
                continue;
            }

            $last = $touchpoints[count($touchpoints) - 1];
            $source = $last['source'];
            $totalRevenue = $this->sumRevenue($touchpoints);

            $credits[$source] ??= ['conversions' => 0, 'revenue' => 0.0];
            $credits[$source]['conversions']++;
            $credits[$source]['revenue'] += $totalRevenue;
        }

        return $this->buildResults($credits, 1.0);
    }

    /**
     * Linear: equal credit across all touchpoints per visitor.
     *
     * @param array<string, list<array{source: string, started_at: string, revenue: float}>> $journeys
     * @return list<AttributionResult>
     */
    private function applyLinear(array $journeys): array
    {
        /** @var array<string, array{conversions: float, revenue: float}> $credits */
        $credits = [];

        foreach ($journeys as $touchpoints) {
            $n = count($touchpoints);

            if ($n === 0) {
                continue;
            }

            $weight = 1.0 / $n;
            $totalRevenue = $this->sumRevenue($touchpoints);

            foreach ($touchpoints as $tp) {
                $source = $tp['source'];
                $credits[$source] ??= ['conversions' => 0.0, 'revenue' => 0.0];
                $credits[$source]['conversions'] += $weight;
                $credits[$source]['revenue'] += $totalRevenue * $weight;
            }
        }

        return $this->buildResultsFloat($credits);
    }

    /**
     * Time-decay: more recent touchpoints receive exponentially more credit.
     *
     * Uses a 7-day half-life. Touchpoints closer to conversion get higher
     * credit, computed from the actual timestamp deltas in the journey.
     *
     * @param array<string, list<array{source: string, started_at: string, revenue: float}>> $journeys
     * @return list<AttributionResult>
     */
    private function applyTimeDecay(array $journeys): array
    {
        /** @var array<string, array{conversions: float, revenue: float}> $credits */
        $credits = [];

        foreach ($journeys as $touchpoints) {
            $n = count($touchpoints);

            if ($n === 0) {
                continue;
            }

            $totalRevenue = $this->sumRevenue($touchpoints);
            $lastTimestamp = strtotime($touchpoints[$n - 1]['started_at']) ?: 0;

            // Compute raw decay weights
            $weights = [];
            $weightSum = 0.0;

            foreach ($touchpoints as $tp) {
                $ts = strtotime($tp['started_at']) ?: 0;
                $deltaSec = max(0, $lastTimestamp - $ts);
                $w = 2.0 ** (-$deltaSec / self::TIME_DECAY_HALF_LIFE);
                $weights[] = $w;
                $weightSum += $w;
            }

            if ($weightSum <= 0.0) {
                continue;
            }

            // Normalize and distribute credit
            foreach ($touchpoints as $i => $tp) {
                $normalizedWeight = $weights[$i] / $weightSum;
                $source = $tp['source'];
                $credits[$source] ??= ['conversions' => 0.0, 'revenue' => 0.0];
                $credits[$source]['conversions'] += $normalizedWeight;
                $credits[$source]['revenue'] += $totalRevenue * $normalizedWeight;
            }
        }

        return $this->buildResultsFloat($credits);
    }

    /**
     * Sum total revenue across a visitor's touchpoints (uses max to avoid double-counting).
     *
     * @param list<array{source: string, started_at: string, revenue: float}> $touchpoints
     */
    private function sumRevenue(array $touchpoints): float
    {
        $max = 0.0;

        foreach ($touchpoints as $tp) {
            if ($tp['revenue'] > $max) {
                $max = $tp['revenue'];
            }
        }

        return $max;
    }

    /**
     * Build results from integer-conversion credits.
     *
     * @param array<string, array{conversions: int, revenue: float}> $credits
     * @return list<AttributionResult>
     */
    private function buildResults(array $credits, float $weight): array
    {
        $results = [];

        foreach ($credits as $source => $data) {
            $results[] = new AttributionResult(
                source: $source,
                conversions: $data['conversions'],
                revenue: round($data['revenue'], 2),
                weight: $weight,
            );
        }

        usort($results, static fn(AttributionResult $a, AttributionResult $b): int => $b->conversions <=> $a->conversions);

        return $results;
    }

    /**
     * Build results from fractional-conversion credits.
     *
     * @param array<string, array{conversions: float, revenue: float}> $credits
     * @return list<AttributionResult>
     */
    private function buildResultsFloat(array $credits): array
    {
        $totalConversions = 0.0;

        foreach ($credits as $data) {
            $totalConversions += $data['conversions'];
        }

        $results = [];

        foreach ($credits as $source => $data) {
            $results[] = new AttributionResult(
                source: $source,
                conversions: (int) round($data['conversions']),
                revenue: round($data['revenue'], 2),
                weight: $totalConversions > 0.0
                    ? round($data['conversions'] / $totalConversions, 2)
                    : 0.0,
            );
        }

        usort($results, static fn(AttributionResult $a, AttributionResult $b): int => $b->conversions <=> $a->conversions);

        return $results;
    }
}
