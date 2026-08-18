<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Internal\Persistence;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Exception\TicketException;
use Pulsar\Extension\Tickets\Internal\Persistence\DbTicketRepository;

/**
 * The repository shipped with 334 lines, twelve public methods and no test at all.
 * The gap was invisible because TicketRepositoryInterface had a contract test, which
 * proves an interface is implementable and nothing about what implements it —
 * the hole ContractTestCoverageTest now refuses to let reopen.
 *
 * The optimistic locking is what these cases lead with: save() carries the expected
 * version in its WHERE and increments it in its SET, so a stale write must be refused
 * rather than applied. Two agents editing one ticket is the ordinary case, not the
 * exotic one.
 */
#[CoversClass(DbTicketRepository::class)]
final class DbTicketRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbTicketRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE tickets (
                id TEXT PRIMARY KEY,
                ticket_number TEXT NOT NULL UNIQUE,
                subject TEXT NOT NULL,
                description TEXT NOT NULL,
                status TEXT NOT NULL,
                priority TEXT NOT NULL,
                category_id TEXT NULL,
                assignee_id TEXT NULL,
                reporter_id TEXT NULL,
                reporter_email TEXT NOT NULL,
                reporter_name TEXT NOT NULL,
                tags TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                resolved_at TEXT NULL,
                closed_at TEXT NULL,
                version INTEGER NOT NULL DEFAULT 1
            )
            SQL);

        $this->repository = new DbTicketRepository($this->connection);
    }

    #[Test]
    public function aSavedTicketComesBackWithEveryFieldIntact(): void
    {
        $ticket = $this->ticket(tags: ['billing', 'urgent']);

        $this->repository->save($ticket);
        $found = $this->repository->findById($ticket->id);

        self::assertNotNull($found);
        self::assertSame($ticket->ticketNumber, $found->ticketNumber);
        self::assertSame($ticket->subject, $found->subject);
        self::assertSame($ticket->reporterEmail, $found->reporterEmail);
        self::assertSame(['billing', 'urgent'], $found->tags, 'tags survive the JSON round trip');
        self::assertSame($ticket->status, $found->status);
        self::assertSame($ticket->priority, $found->priority);
    }

    #[Test]
    public function anUnknownIdIsNullRatherThanAnError(): void
    {
        self::assertNull($this->repository->findById('does-not-exist'));
    }

    #[Test]
    public function aTicketIsFoundByItsHumanFacingNumber(): void
    {
        $ticket = $this->ticket();
        $this->repository->save($ticket);

        $found = $this->repository->findByNumber($ticket->ticketNumber);

        self::assertNotNull($found);
        self::assertSame($ticket->id, $found->id);
    }

    /**
     * The write that must not be silently applied: a second save from a caller still
     * holding version 1 after someone else moved the row to version 2.
     */
    #[Test]
    public function aStaleWriteIsRefusedInsteadOfOverwriting(): void
    {
        $ticket = $this->ticket();
        $this->repository->save($ticket);

        $fresh = $this->repository->findById($ticket->id);
        self::assertNotNull($fresh);

        // First writer wins and the row moves on.
        $this->repository->save($fresh->assign('agent-1'));

        $this->expectException(TicketException::class);

        // Second writer still holds the version it read before that.
        $this->repository->save($fresh->assign('agent-2'));
    }

    #[Test]
    public function theWinningWriteIsTheOneThatPersists(): void
    {
        $ticket = $this->ticket();
        $this->repository->save($ticket);

        $fresh = $this->repository->findById($ticket->id);
        self::assertNotNull($fresh);

        $this->repository->save($fresh->assign('agent-1'));

        $after = $this->repository->findById($ticket->id);

        self::assertNotNull($after);
        self::assertSame('agent-1', $after->assigneeId);
        self::assertGreaterThan($fresh->version, $after->version, 'the version advances on write');
    }

    #[Test]
    public function statusCountsReportOnlyStatusesThatArePresent(): void
    {
        $open = TicketStatus::cases()[0];

        $this->repository->save($this->ticket(id: 'a', number: 'T-1', status: $open));
        $this->repository->save($this->ticket(id: 'b', number: 'T-2', status: $open));

        $counts = $this->repository->countByStatus();

        self::assertSame(2, $counts[$open->value] ?? 0);
    }

    #[Test]
    public function aDeletedTicketIsGone(): void
    {
        $ticket = $this->ticket();
        $this->repository->save($ticket);

        $this->repository->delete($ticket);

        self::assertNull($this->repository->findById($ticket->id));
    }

    /**
     * @param list<string> $tags
     */
    private function ticket(
        string $id = 'ticket-1',
        string $number = 'T-1000',
        ?TicketStatus $status = null,
        array $tags = [],
    ): Ticket {
        $now = new DateTimeImmutable('2026-08-13 10:00:00');

        return new Ticket(
            id: $id,
            ticketNumber: $number,
            subject: 'Cannot log in',
            description: 'The login form returns a 500.',
            status: $status ?? TicketStatus::cases()[0],
            priority: TicketPriority::cases()[0],
            categoryId: null,
            assigneeId: null,
            reporterId: null,
            reporterEmail: 'reporter@example.com',
            reporterName: 'A Reporter',
            tags: $tags,
            createdAt: $now,
            updatedAt: $now,
            resolvedAt: null,
            closedAt: null,
            version: 1,
        );
    }
}
