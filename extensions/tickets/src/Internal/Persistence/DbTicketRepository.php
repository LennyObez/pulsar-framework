<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Exception\TicketException;

use function ceil;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function str_replace;
use function trim;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository; use TicketRepositoryInterface for public API')]
final readonly class DbTicketRepository implements TicketRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM tickets WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_NUMBER = <<<'SQL'
        SELECT * FROM tickets WHERE ticket_number = :ticket_number
        SQL;

    private const string SQL_COUNT_BY_STATUS = <<<'SQL'
        SELECT status, COUNT(*) AS cnt FROM tickets GROUP BY status
        SQL;

    private const string SQL_COUNT_RESOLVED_TODAY = <<<'SQL'
        SELECT COUNT(*) AS cnt FROM tickets
        WHERE resolved_at >= :today_start AND resolved_at < :tomorrow_start
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'ticket_number', 'subject', 'description', 'status', 'priority',
        'category_id', 'assignee_id', 'reporter_id', 'reporter_email', 'reporter_name',
        'tags', 'created_at', 'updated_at', 'resolved_at', 'closed_at', 'version',
    ];

    private const array UPSERT_UPDATE = [
        'subject', 'description', 'status', 'priority', 'category_id', 'assignee_id',
        'tags', 'updated_at', 'resolved_at', 'closed_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?Ticket
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByNumber(string $ticketNumber): ?Ticket
    {
        $result = $this->connection->query(self::SQL_FIND_BY_NUMBER, ['ticket_number' => $ticketNumber]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByStatus(
        TicketStatus $status,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult {
        return $this->findAll($page, $perPage, status: $status);
    }

    public function findByAssignee(
        string $assigneeId,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult {
        return $this->findAll($page, $perPage, assigneeId: $assigneeId);
    }

    public function findByReporter(
        string $reporterEmail,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(
            'SELECT COUNT(*) AS total FROM tickets WHERE reporter_email = :email',
            ['email' => $reporterEmail],
        );
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(
            'SELECT * FROM tickets WHERE reporter_email = :email ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            ['email' => $reporterEmail, 'limit' => $perPage, 'offset' => $offset],
        );
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

    public function search(
        string $query,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $likeQuery = '%' . $escaped . '%';

        $countResult = $this->connection->query(
            'SELECT COUNT(*) AS total FROM tickets WHERE subject LIKE :q OR description LIKE :q2 OR ticket_number LIKE :q3',
            ['q' => $likeQuery, 'q2' => $likeQuery, 'q3' => $likeQuery],
        );
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(
            'SELECT * FROM tickets WHERE subject LIKE :q OR description LIKE :q2 OR ticket_number LIKE :q3 ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            ['q' => $likeQuery, 'q2' => $likeQuery, 'q3' => $likeQuery, 'limit' => $perPage, 'offset' => $offset],
        );
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

    public function findAll(
        int $page = 1,
        int $perPage = 25,
        ?TicketStatus $status = null,
        ?TicketPriority $priority = null,
        ?string $categoryId = null,
        ?string $assigneeId = null,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = '1=1';
        $bindings = [];

        if ($status !== null) {
            $where .= ' AND status = :status';
            $bindings['status'] = $status->value;
        }

        if ($priority !== null) {
            $where .= ' AND priority = :priority';
            $bindings['priority'] = $priority->value;
        }

        if ($categoryId !== null) {
            $where .= ' AND category_id = :category_id';
            $bindings['category_id'] = $categoryId;
        }

        if ($assigneeId !== null) {
            $where .= ' AND assignee_id = :assignee_id';
            $bindings['assignee_id'] = $assigneeId;
        }

        $countResult = $this->connection->query(
            "SELECT COUNT(*) AS total FROM tickets WHERE $where",
            $bindings,
        );
        $total = $countResult->first()?->getInt('total') ?? 0;

        $bindings['limit'] = $perPage;
        $bindings['offset'] = $offset;

        $dataResult = $this->connection->query(
            "SELECT * FROM tickets WHERE $where ORDER BY CASE priority WHEN 'critical' THEN 5 WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END DESC, created_at DESC LIMIT :limit OFFSET :offset",
            $bindings,
        );
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
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $result = $this->connection->query(self::SQL_COUNT_BY_STATUS, []);
        $counts = [];

        foreach ($result->all() as $row) {
            $counts[$row->getString('status')] = $row->getInt('cnt');
        }

        return $counts;
    }

    public function countResolvedToday(): int
    {
        $today = new DateTimeImmutable('today');
        $tomorrow = new DateTimeImmutable('tomorrow');

        $result = $this->connection->query(self::SQL_COUNT_RESOLVED_TODAY, [
            'today_start' => $today->format('c'),
            'tomorrow_start' => $tomorrow->format('c'),
        ]);

        return $result->first()?->getInt('cnt') ?? 0;
    }

    public function save(Ticket $ticket): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'tickets',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
            extraWhere: 'tickets.version = :expected_version',
            extraSet: 'version = tickets.version + 1',
        );

        $affected = $this->connection->execute($sql, [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticketNumber,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'category_id' => $ticket->categoryId,
            'assignee_id' => $ticket->assigneeId,
            'reporter_id' => $ticket->reporterId,
            'reporter_email' => $ticket->reporterEmail,
            'reporter_name' => $ticket->reporterName,
            'tags' => json_encode($ticket->tags, JSON_THROW_ON_ERROR),
            'created_at' => $ticket->createdAt->format('c'),
            'updated_at' => $ticket->updatedAt->format('c'),
            'resolved_at' => $ticket->resolvedAt?->format('c'),
            'closed_at' => $ticket->closedAt?->format('c'),
            'version' => $ticket->version,
            'expected_version' => $ticket->version,
        ]);

        if ($affected === 0) {
            throw TicketException::concurrencyConflict($ticket->id, $ticket->version);
        }
    }

    public function delete(Ticket $ticket): void
    {
        $this->connection->execute(
            'DELETE FROM tickets WHERE id = :id',
            ['id' => $ticket->id],
        );
    }

    private static function hydrate(Row $row): Ticket
    {
        $tagsRaw = $row->getNullableString('tags');
        /** @var list<string> $tags */
        $tags = $tagsRaw !== null && $tagsRaw !== '' && $tagsRaw !== '[]'
            ? (array) json_decode($tagsRaw, true, 512, JSON_THROW_ON_ERROR)
            : [];

        return new Ticket(
            id: $row->getString('id'),
            ticketNumber: $row->getString('ticket_number'),
            subject: $row->getString('subject'),
            description: $row->getString('description'),
            status: TicketStatus::from($row->getString('status')),
            priority: TicketPriority::from($row->getString('priority')),
            categoryId: $row->getNullableString('category_id'),
            assigneeId: $row->getNullableString('assignee_id'),
            reporterId: $row->getNullableString('reporter_id'),
            reporterEmail: $row->getString('reporter_email'),
            reporterName: $row->getString('reporter_name'),
            tags: $tags,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
            resolvedAt: self::toDateTime($row->getNullableString('resolved_at')),
            closedAt: self::toDateTime($row->getNullableString('closed_at')),
            version: $row->getInt('version'),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
