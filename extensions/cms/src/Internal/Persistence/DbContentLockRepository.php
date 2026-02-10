<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Workflow\ContentLock;

#[Internal(reason: 'Raw-DB repository — use ContentLockServiceInterface for public API')]
final readonly class DbContentLockRepository
{
    private const string SQL_FIND_ACTIVE = <<<'SQL'
        SELECT * FROM cms_content_locks
        WHERE content_id = :content_id AND expires_at > NOW()
        SQL;

    private const string SQL_ACQUIRE = <<<'SQL'
        INSERT INTO cms_content_locks (content_id, locked_by, locked_at, expires_at, locale)
        VALUES (:content_id, :locked_by, :locked_at, :expires_at, :locale)
        ON CONFLICT (content_id) DO UPDATE
            SET locked_by = EXCLUDED.locked_by,
                locked_at = EXCLUDED.locked_at,
                expires_at = EXCLUDED.expires_at,
                locale = EXCLUDED.locale
            WHERE cms_content_locks.expires_at < :now
        SQL;

    private const string SQL_RELEASE = <<<'SQL'
        DELETE FROM cms_content_locks
        WHERE content_id = :content_id AND locked_by = :locked_by
        SQL;

    private const string SQL_FORCE_RELEASE = <<<'SQL'
        DELETE FROM cms_content_locks WHERE content_id = :content_id
        SQL;

    private const string SQL_HEARTBEAT = <<<'SQL'
        UPDATE cms_content_locks SET expires_at = :expires_at
        WHERE content_id = :content_id AND locked_by = :locked_by AND expires_at > NOW()
        SQL;

    private const string SQL_CLEANUP_EXPIRED = <<<'SQL'
        DELETE FROM cms_content_locks WHERE expires_at <= NOW()
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findByContent(string $contentId): ?ContentLock
    {
        $result = $this->connection->query(self::SQL_FIND_ACTIVE, [
            'content_id' => $contentId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function acquire(ContentLock $lock): bool
    {
        $affected = $this->connection->execute(self::SQL_ACQUIRE, [
            'content_id' => $lock->contentId,
            'locked_by' => $lock->lockedBy,
            'locked_at' => $lock->lockedAt->format('c'),
            'expires_at' => $lock->expiresAt->format('c'),
            'locale' => $lock->locale,
            'now' => $lock->lockedAt->format('c'),
        ]);

        return $affected > 0;
    }

    public function release(string $contentId, string $userId): void
    {
        $this->connection->execute(self::SQL_RELEASE, [
            'content_id' => $contentId,
            'locked_by' => $userId,
        ]);
    }

    public function forceRelease(string $contentId): void
    {
        $this->connection->execute(self::SQL_FORCE_RELEASE, [
            'content_id' => $contentId,
        ]);
    }

    public function heartbeat(string $contentId, string $userId, DateTimeImmutable $newExpiry): bool
    {
        $affected = $this->connection->execute(self::SQL_HEARTBEAT, [
            'content_id' => $contentId,
            'locked_by' => $userId,
            'expires_at' => $newExpiry->format('c'),
        ]);

        return $affected > 0;
    }

    public function cleanupExpired(): int
    {
        return $this->connection->execute(self::SQL_CLEANUP_EXPIRED);
    }

    private static function hydrate(Row $row): ContentLock
    {
        return new ContentLock(
            contentId: $row->getString('content_id'),
            lockedBy: $row->getString('locked_by'),
            lockedAt: new DateTimeImmutable($row->getString('locked_at')),
            expiresAt: new DateTimeImmutable($row->getString('expires_at')),
            locale: $row->getNullableString('locale'),
        );
    }
}
