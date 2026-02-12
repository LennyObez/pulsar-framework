<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Internal\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\FeedbackStatus;

use function ceil;
use function json_decode;
use function json_encode;
use function max;
use function min;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed feedback repository using portable SQL (UpsertBuilder).
 */
#[Internal(reason: 'Raw-DB repository — use FeedbackRepositoryInterface for public API')]
final readonly class DbFeedbackRepository implements FeedbackRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT *
        FROM feedback
        WHERE id = :id
        SQL;

    private const string SQL_COUNT_BY_USER = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM feedback
        WHERE user_id = :user_id
        SQL;

    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT *
        FROM feedback
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        SQL;

    private const string SQL_COUNT_BY_USER_TODAY = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM feedback
        WHERE user_id = :user_id
            AND created_at >= :today_start
            AND created_at < :tomorrow_start
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'user_id', 'category', 'description', 'context',
        'status', 'admin_response', 'github_issue_url',
        'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'category', 'description', 'context', 'status',
        'admin_response', 'github_issue_url', 'updated_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(Feedback $feedback): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'feedback',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $feedback->id,
            'user_id' => $feedback->userId,
            'category' => $feedback->category->value,
            'description' => $feedback->description,
            'context' => json_encode($feedback->context, JSON_THROW_ON_ERROR),
            'status' => $feedback->status->value,
            'admin_response' => $feedback->adminResponse,
            'github_issue_url' => $feedback->githubIssueUrl,
            'created_at' => $feedback->createdAt->format('c'),
            'updated_at' => $feedback->updatedAt->format('c'),
        ]);
    }

    public function findById(string $id): ?Feedback
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * @return PaginationResult<Feedback>
     */
    public function findByUser(string $userId, int $page, int $perPage): PaginationResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_USER, [
            'user_id' => $userId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql = self::SQL_FIND_BY_USER . ' LIMIT :limit OFFSET :offset';
        $dataResult = $this->connection->query($selectSql, [
            'user_id' => $userId,
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

    /**
     * @return PaginationResult<Feedback>
     */
    public function findAll(
        int $page,
        int $perPage,
        ?FeedbackCategory $category = null,
        ?FeedbackStatus $status = null,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($category !== null) {
            $where[] = 'category = :category';
            $params['category'] = $category->value;
        }

        if ($status !== null) {
            $where[] = 'status = :status';
            $params['status'] = $status->value;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) AS total FROM feedback {$whereClause}";
        $countResult = $this->connection->query($countSql, $params);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql = "SELECT * FROM feedback {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $dataResult = $this->connection->query($selectSql, [
            ...$params,
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

    public function countByUserToday(string $userId): int
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $tomorrow = $today->modify('+1 day');

        $result = $this->connection->query(self::SQL_COUNT_BY_USER_TODAY, [
            'user_id' => $userId,
            'today_start' => $today->format('c'),
            'tomorrow_start' => $tomorrow->format('c'),
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    private static function hydrate(Row $row): Feedback
    {
        /** @var array<string, mixed> $context */
        $context = json_decode($row->getString('context'), true, 512, JSON_THROW_ON_ERROR);

        return new Feedback(
            id: $row->getString('id'),
            userId: $row->getString('user_id'),
            category: FeedbackCategory::from($row->getString('category')),
            description: $row->getString('description'),
            context: $context,
            status: FeedbackStatus::from($row->getString('status')),
            adminResponse: $row->getNullableString('admin_response'),
            githubIssueUrl: $row->getNullableString('github_issue_url'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
