<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Workflow;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function assert;

/**
 * Pessimistic lease-based content lock service.
 *
 * Uses INSERT ... ON CONFLICT DO NOTHING for atomic lock acquisition and
 * automatic expiration (30 minutes) with heartbeat renewal.
 */
#[Internal]
final readonly class ContentLockService implements ContentLockServiceInterface
{
    private const int LOCK_TTL_MINUTES = 30;

    public function __construct(
        private ConnectionInterface $db,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function acquire(string $contentId, string $userId, ?string $locale = null): ?ContentLock
    {
        // Clean up any expired lock for this content first
        $this->db->execute(
            'DELETE FROM cms_content_locks WHERE content_id = :content_id AND expires_at < NOW()',
            ['content_id' => $contentId],
        );

        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . self::LOCK_TTL_MINUTES . ' minutes');

        $affected = $this->db->execute(
            <<<'SQL'
                INSERT INTO cms_content_locks (content_id, locked_by, locked_at, expires_at, locale)
                VALUES (:content_id, :user_id, :locked_at, :expires_at, :locale)
                ON CONFLICT (content_id) DO NOTHING
                SQL,
            [
                'content_id' => $contentId,
                'user_id' => $userId,
                'locked_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'locale' => $locale,
            ],
        );

        if ($affected === 0) {
            // Lock was not acquired — another user holds it
            return null;
        }

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $userId,
            'cms.content.lock.acquired',
            'content:' . $contentId,
            ['locale' => $locale],
        );

        return new ContentLock(
            contentId: $contentId,
            lockedBy: $userId,
            lockedAt: $now,
            expiresAt: $expiresAt,
            locale: $locale,
        );
    }

    public function release(string $contentId, string $userId): void
    {
        $this->db->execute(
            'DELETE FROM cms_content_locks WHERE content_id = :content_id AND locked_by = :user_id',
            [
                'content_id' => $contentId,
                'user_id' => $userId,
            ],
        );

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $userId,
            'cms.content.lock.released',
            'content:' . $contentId,
        );
    }

    public function heartbeat(string $contentId, string $userId): ?ContentLock
    {
        $newExpiresAt = new DateTimeImmutable()->modify('+' . self::LOCK_TTL_MINUTES . ' minutes');

        $affected = $this->db->execute(
            <<<'SQL'
                UPDATE cms_content_locks
                SET expires_at = :expires_at
                WHERE content_id = :content_id AND locked_by = :user_id
                SQL,
            [
                'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
                'content_id' => $contentId,
                'user_id' => $userId,
            ],
        );

        if ($affected === 0) {
            return null;
        }

        // Re-read the lock to return the updated state
        return $this->isLocked($contentId);
    }

    public function forceUnlock(string $contentId, ?string $actorId = null): void
    {
        $this->db->execute(
            'DELETE FROM cms_content_locks WHERE content_id = :content_id',
            ['content_id' => $contentId],
        );

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $actorId,
            'cms.content.lock.force_unlocked',
            'content:' . $contentId,
        );
    }

    public function isLocked(string $contentId, ?string $locale = null): ?ContentLock
    {
        $sql = 'SELECT content_id, locked_by, locked_at, expires_at, locale FROM cms_content_locks WHERE content_id = :content_id';
        $bindings = ['content_id' => $contentId];

        if ($locale !== null) {
            $sql .= ' AND (locale = :locale OR locale IS NULL)';
            $bindings['locale'] = $locale;
        }

        $sql .= ' LIMIT 1';

        $result = $this->db->query($sql, $bindings);

        if ($result->isEmpty()) {
            return null;
        }

        $row = $result->first();
        assert($row !== null);

        $lock = new ContentLock(
            contentId: $row->getString('content_id'),
            lockedBy: $row->getString('locked_by'),
            lockedAt: new DateTimeImmutable($row->getString('locked_at')),
            expiresAt: new DateTimeImmutable($row->getString('expires_at')),
            locale: $row->getNullableString('locale'),
        );

        // Check if expired
        if ($lock->isExpired()) {
            $this->db->execute(
                'DELETE FROM cms_content_locks WHERE content_id = :content_id',
                ['content_id' => $contentId],
            );

            return null;
        }

        return $lock;
    }

    public function cleanupExpired(): int
    {
        return $this->db->execute('DELETE FROM cms_content_locks WHERE expires_at < NOW()');
    }
}
