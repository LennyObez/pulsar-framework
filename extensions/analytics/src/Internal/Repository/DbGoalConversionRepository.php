<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\GoalConversion;

#[Internal(reason: 'Concrete repository; used internally by GoalService')]
final readonly class DbGoalConversionRepository
{
    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO analytics_goal_conversions (id, goal_id, site_id, visitor_id, session_id, revenue_value, created_at)
        VALUES (:id, :goal_id, :site_id, :visitor_id, :session_id, :revenue_value, :created_at)
        SQL;

    private const string SQL_FIND_BY_GOAL = <<<'SQL'
        SELECT * FROM analytics_goal_conversions
        WHERE goal_id = :goal_id AND created_at >= :from AND created_at <= :to
        ORDER BY created_at DESC
        LIMIT :limit
        SQL;

    private const string SQL_COUNT_BY_GOAL = <<<'SQL'
        SELECT COUNT(*) AS total FROM analytics_goal_conversions
        WHERE goal_id = :goal_id AND created_at >= :from AND created_at <= :to
        SQL;

    private const string SQL_COUNT_BY_SITE = <<<'SQL'
        SELECT COUNT(*) AS total FROM analytics_goal_conversions
        WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to
        SQL;

    private const string SQL_SUM_REVENUE_BY_GOAL = <<<'SQL'
        SELECT COALESCE(SUM(revenue_value), 0) AS total_revenue FROM analytics_goal_conversions
        WHERE goal_id = :goal_id AND created_at >= :from AND created_at <= :to
        SQL;

    private const string SQL_DELETE_OLDER_THAN = <<<'SQL'
        DELETE FROM analytics_goal_conversions WHERE created_at < :before
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(GoalConversion $conversion): void
    {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $conversion->id,
            'goal_id' => $conversion->goalId,
            'site_id' => $conversion->siteId,
            'visitor_id' => $conversion->visitorId,
            'session_id' => $conversion->sessionId,
            'revenue_value' => $conversion->revenueValue,
            'created_at' => $conversion->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<GoalConversion>
     */
    public function findByGoal(string $goalId, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array
    {
        return $this->connection->query(self::SQL_FIND_BY_GOAL, [
            'goal_id' => $goalId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ])->map(self::hydrate(...));
    }

    public function countByGoal(string $goalId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $row = $this->connection->query(self::SQL_COUNT_BY_GOAL, [
            'goal_id' => $goalId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->first();

        return $row?->getInt('total') ?? 0;
    }

    public function countBySite(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $row = $this->connection->query(self::SQL_COUNT_BY_SITE, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->first();

        return $row?->getInt('total') ?? 0;
    }

    public function sumRevenueByGoal(string $goalId, DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        $row = $this->connection->query(self::SQL_SUM_REVENUE_BY_GOAL, [
            'goal_id' => $goalId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->first();

        return $row?->getFloat('total_revenue') ?? 0.0;
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->connection->execute(self::SQL_DELETE_OLDER_THAN, [
            'before' => $before->format('Y-m-d H:i:s'),
        ]);
    }

    private static function hydrate(Row $row): GoalConversion
    {
        $revenue = $row->getNullableFloat('revenue_value');

        return new GoalConversion(
            id: $row->getString('id'),
            goalId: $row->getString('goal_id'),
            siteId: $row->getString('site_id'),
            visitorId: $row->getString('visitor_id'),
            sessionId: $row->getString('session_id'),
            revenueValue: $revenue,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
