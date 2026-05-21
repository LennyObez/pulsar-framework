<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\Goal;
use Pulsar\Extension\Analytics\Domain\GoalType;

#[Internal(reason: 'Concrete repository; used internally by GoalService')]
final readonly class DbGoalRepository
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM analytics_goals WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_SITE = <<<'SQL'
        SELECT * FROM analytics_goals WHERE site_id = :site_id ORDER BY created_at ASC
        SQL;

    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO analytics_goals (id, site_id, name, goal_type, target_value, created_at)
        VALUES (:id, :site_id, :name, :goal_type, :target_value, :created_at)
        SQL;

    private const string SQL_UPDATE = <<<'SQL'
        UPDATE analytics_goals SET
            name = :name,
            goal_type = :goal_type,
            target_value = :target_value
        WHERE id = :id
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM analytics_goals WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?Goal
    {
        $row = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @return list<Goal>
     */
    public function findBySite(string $siteId): array
    {
        return $this->connection->query(self::SQL_FIND_BY_SITE, [
            'site_id' => $siteId,
        ])->map(self::hydrate(...));
    }

    public function save(Goal $goal): void
    {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $goal->id,
            'site_id' => $goal->siteId,
            'name' => $goal->name,
            'goal_type' => $goal->goalType->value,
            'target_value' => $goal->targetValue,
            'created_at' => $goal->createdAt->format('Y-m-d H:i:s'),
        ]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function update(Goal $goal): void
    {
        $this->connection->execute(self::SQL_UPDATE, [
            'id' => $goal->id,
            'name' => $goal->name,
            'goal_type' => $goal->goalType->value,
            'target_value' => $goal->targetValue,
        ]);
    }

    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    private static function hydrate(Row $row): Goal
    {
        return new Goal(
            id: $row->getString('id'),
            siteId: $row->getString('site_id'),
            name: $row->getString('name'),
            goalType: GoalType::from($row->getString('goal_type')),
            targetValue: $row->getString('target_value'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
