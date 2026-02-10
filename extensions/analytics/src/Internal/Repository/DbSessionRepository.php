<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Session;

#[Internal(reason: 'Raw-DB repository — use SessionRepositoryInterface for public API')]
final readonly class DbSessionRepository implements SessionRepositoryInterface
{
    private const string SQL_FIND_ACTIVE_BY_VISITOR = <<<'SQL'
        SELECT * FROM analytics_sessions
        WHERE site_id = :site_id AND visitor_id = :visitor_id AND ended_at >= :since
        ORDER BY ended_at DESC
        LIMIT 1
        SQL;

    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO analytics_sessions (
            id, site_id, visitor_id, session_id, entry_page, exit_page,
            page_count, duration_seconds, is_bounce, started_at, ended_at
        ) VALUES (
            :id, :site_id, :visitor_id, :session_id, :entry_page, :exit_page,
            :page_count, :duration_seconds, :is_bounce, :started_at, :ended_at
        )
        SQL;

    private const string SQL_UPDATE = <<<'SQL'
        UPDATE analytics_sessions SET
            exit_page = :exit_page,
            page_count = :page_count,
            duration_seconds = :duration_seconds,
            is_bounce = :is_bounce,
            ended_at = :ended_at
        WHERE id = :id
        SQL;

    private const string SQL_DELETE_OLDER_THAN = <<<'SQL'
        DELETE FROM analytics_sessions WHERE ended_at < :before
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findActiveByVisitor(string $siteId, string $visitorId, DateTimeImmutable $since): ?Session
    {
        $row = $this->connection->query(self::SQL_FIND_ACTIVE_BY_VISITOR, [
            'site_id' => $siteId,
            'visitor_id' => $visitorId,
            'since' => $since->format('Y-m-d H:i:s'),
        ])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function save(Session $session): void
    {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $session->id,
            'site_id' => $session->siteId,
            'visitor_id' => $session->visitorId,
            'session_id' => $session->sessionId,
            'entry_page' => $session->entryPage,
            'exit_page' => $session->exitPage,
            'page_count' => $session->pageCount,
            'duration_seconds' => $session->durationSeconds,
            'is_bounce' => $session->isBounce ? 1 : 0,
            'started_at' => $session->startedAt->format('Y-m-d H:i:s'),
            'ended_at' => $session->endedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function update(Session $session): void
    {
        $this->connection->execute(self::SQL_UPDATE, [
            'id' => $session->id,
            'exit_page' => $session->exitPage,
            'page_count' => $session->pageCount,
            'duration_seconds' => $session->durationSeconds,
            'is_bounce' => $session->isBounce ? 1 : 0,
            'ended_at' => $session->endedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->connection->execute(self::SQL_DELETE_OLDER_THAN, [
            'before' => $before->format('Y-m-d H:i:s'),
        ]);
    }

    private static function hydrate(Row $row): Session
    {
        return new Session(
            id: $row->getString('id'),
            siteId: $row->getString('site_id'),
            visitorId: $row->getString('visitor_id'),
            sessionId: $row->getString('session_id'),
            entryPage: $row->getString('entry_page'),
            exitPage: $row->getString('exit_page'),
            pageCount: $row->getInt('page_count'),
            durationSeconds: $row->getInt('duration_seconds'),
            isBounce: $row->getBool('is_bounce'),
            startedAt: new DateTimeImmutable($row->getString('started_at')),
            endedAt: new DateTimeImmutable($row->getString('ended_at')),
        );
    }
}
