<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\ModerationAction;
use Pulsar\Extension\Forum\Report\ForumModerationLog;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;

use function ceil;
use function max;
use function min;

#[Internal(reason: 'Raw-DB repository; use ForumModerationLogRepositoryInterface for public API')]
final readonly class DbForumModerationLogRepository implements ForumModerationLogRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT l.*
        FROM forum_moderation_log l
        WHERE l.id = :id
        SQL;

    private const string SQL_COUNT_BY_MODERATOR = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_moderation_log l
        WHERE l.moderator_id = :moderator_id
        SQL;

    private const string SQL_FIND_BY_MODERATOR = <<<'SQL'
        SELECT l.*
        FROM forum_moderation_log l
        WHERE l.moderator_id = :moderator_id
        ORDER BY l.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_FIND_BY_TARGET = <<<'SQL'
        SELECT l.*
        FROM forum_moderation_log l
        WHERE l.target_type = :target_type
            AND l.target_id = :target_id
        ORDER BY l.created_at DESC
        LIMIT 100
        SQL;

    private const string SQL_COUNT_RECENT = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_moderation_log l
        SQL;

    private const string SQL_FIND_RECENT = <<<'SQL'
        SELECT l.*
        FROM forum_moderation_log l
        ORDER BY l.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'moderator_id', 'action', 'target_type',
        'target_id', 'reason', 'created_at',
    ];

    private const array UPSERT_UPDATE = [
        'action', 'reason',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?ForumModerationLog
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByModerator(string $moderatorId, int $page = 1, int $perPage = 20): PaginationResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_MODERATOR, [
            'moderator_id' => $moderatorId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_MODERATOR, [
            'moderator_id' => $moderatorId,
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function findByTarget(string $targetType, string $targetId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_TARGET, [
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findRecent(int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_RECENT);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_RECENT, [
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function save(ForumModerationLog $log): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_moderation_log',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $log->id,
            'moderator_id' => $log->moderatorId,
            'action' => $log->action->value,
            'target_type' => $log->targetType,
            'target_id' => $log->targetId,
            'reason' => $log->reason,
            'created_at' => $log->createdAt->format('c'),
        ]);
    }

    private static function hydrate(Row $row): ForumModerationLog
    {
        return new ForumModerationLog(
            id: $row->getString('id'),
            moderatorId: $row->getString('moderator_id'),
            action: ModerationAction::from($row->getString('action')),
            targetType: $row->getString('target_type'),
            targetId: $row->getString('target_id'),
            reason: $row->getString('reason'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
