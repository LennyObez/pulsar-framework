<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Workflow;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Workflow\ContentLockService;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(ContentLockService::class)]
final class ContentLockServiceTest extends TestCase
{
    private const string CONTENT_ID = 'content-001';
    private const string USER_A = 'user-alice';
    private const string USER_B = 'user-bob';

    private ConnectionInterface&Stub $db;
    private AuditLoggerInterface&Stub $auditLogger;
    private ContentLockService $service;

    protected function setUp(): void
    {
        $this->db = $this->createStub(ConnectionInterface::class);
        $this->db->method('driver')->willReturn(Driver::MySQL);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));
        $this->service = new ContentLockService($this->db, $this->auditLogger);
    }

    #[Test]
    public function acquire_succeeds_when_no_lock_exists(): void
    {
        $this->db->method('execute')->willReturn(1);

        $lock = $this->service->acquire(self::CONTENT_ID, self::USER_A);

        self::assertNotNull($lock);
        self::assertSame(self::CONTENT_ID, $lock->contentId);
        self::assertSame(self::USER_A, $lock->lockedBy);
        self::assertNull($lock->locale);
        self::assertFalse($lock->isExpired());
    }

    #[Test]
    public function acquire_succeeds_with_locale(): void
    {
        $this->db->method('execute')->willReturn(1);

        $lock = $this->service->acquire(self::CONTENT_ID, self::USER_A, 'fr');

        self::assertNotNull($lock);
        self::assertSame('fr', $lock->locale);
    }

    #[Test]
    public function acquire_returns_null_when_active_lock_held_by_another_user(): void
    {
        // The atomic upsert returns affected=0 when an active (non-expired) lock exists
        $this->db->method('execute')->willReturn(0);

        $result = $this->service->acquire(self::CONTENT_ID, self::USER_B);

        self::assertNull($result);
    }

    #[Test]
    public function acquire_does_not_audit_log_on_failure(): void
    {
        $this->db->method('execute')->willReturn(0);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('log');

        $service = new ContentLockService($this->db, $auditLogger);
        $result = $service->acquire(self::CONTENT_ID, self::USER_B);

        self::assertNull($result);
    }

    #[Test]
    public function acquire_succeeds_when_existing_lock_is_expired(): void
    {
        // The atomic upsert with WHERE expires_at < :now returns affected=1
        // when the existing lock has expired, atomically replacing it
        $this->db->method('execute')->willReturn(1);

        $lock = $this->service->acquire(self::CONTENT_ID, self::USER_B);

        self::assertNotNull($lock);
        self::assertSame(self::USER_B, $lock->lockedBy);
    }

    #[Test]
    public function acquire_uses_single_atomic_statement(): void
    {
        // Verify that acquire issues exactly ONE execute call (atomic upsert),
        // not a separate DELETE followed by INSERT (which would be two calls)
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    // MySQL: ON DUPLICATE KEY UPDATE with IF(expires_at < :now, ...)
                    // PostgreSQL: ON CONFLICT DO UPDATE WHERE expires_at < :now
                    $isMySql = str_contains($sql, 'ON DUPLICATE KEY UPDATE')
                        && str_contains($sql, 'IF(expires_at < :now');
                    $isPg = str_contains($sql, 'ON CONFLICT')
                        && str_contains($sql, 'DO UPDATE')
                        && str_contains($sql, 'WHERE cms_content_locks.expires_at < :now');

                    return $isMySql || $isPg;
                }),
                self::callback(static function (array $bindings): bool {
                    // Must include :now parameter for the conditional logic
                    return isset($bindings['now'])
                        && isset($bindings['content_id'])
                        && isset($bindings['user_id']);
                }),
            )
            ->willReturn(1);

        $service = new ContentLockService($db, $this->auditLogger);
        $service->acquire(self::CONTENT_ID, self::USER_A);
    }

    #[Test]
    public function acquire_sets_expiry_thirty_minutes_in_future(): void
    {
        $this->db->method('execute')->willReturn(1);

        $before = new DateTimeImmutable();
        $lock = $this->service->acquire(self::CONTENT_ID, self::USER_A);
        $after = new DateTimeImmutable();

        self::assertNotNull($lock);

        $expectedMin = $before->modify('+30 minutes')->getTimestamp();
        $expectedMax = $after->modify('+30 minutes')->getTimestamp();

        self::assertGreaterThanOrEqual($expectedMin, $lock->expiresAt->getTimestamp());
        self::assertLessThanOrEqual($expectedMax, $lock->expiresAt->getTimestamp());
    }

    #[Test]
    public function acquire_logs_audit_event_on_success(): void
    {
        $this->db->method('execute')->willReturn(1);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                self::USER_A,
                'cms.content.lock.acquired',
                'content:' . self::CONTENT_ID,
                ['locale' => null],
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $service = new ContentLockService($this->db, $auditLogger);
        $service->acquire(self::CONTENT_ID, self::USER_A);
    }

    #[Test]
    public function release_deletes_lock_and_logs_audit(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM cms_content_locks'),
                self::callback(static fn(array $b): bool => $b['content_id'] === self::CONTENT_ID && $b['user_id'] === self::USER_A),
            )
            ->willReturn(1);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                self::USER_A,
                'cms.content.lock.released',
                'content:' . self::CONTENT_ID,
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $service = new ContentLockService($db, $auditLogger);
        $service->release(self::CONTENT_ID, self::USER_A);
    }

    #[Test]
    public function heartbeat_returns_null_when_no_matching_lock(): void
    {
        $this->db->method('execute')->willReturn(0);

        $result = $this->service->heartbeat(self::CONTENT_ID, self::USER_A);

        self::assertNull($result);
    }

    #[Test]
    public function heartbeat_returns_updated_lock_on_success(): void
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+30 minutes');

        $this->db->method('execute')->willReturn(1);
        $this->db->method('query')->willReturn(new Result([
            new Row([
                'content_id' => self::CONTENT_ID,
                'locked_by' => self::USER_A,
                'locked_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'locale' => null,
            ]),
        ]));

        $lock = $this->service->heartbeat(self::CONTENT_ID, self::USER_A);

        self::assertNotNull($lock);
        self::assertSame(self::CONTENT_ID, $lock->contentId);
        self::assertSame(self::USER_A, $lock->lockedBy);
    }

    #[Test]
    public function force_unlock_deletes_and_logs(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM cms_content_locks'),
                ['content_id' => self::CONTENT_ID],
            )
            ->willReturn(1);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                'admin-001',
                'cms.content.lock.force_unlocked',
                'content:' . self::CONTENT_ID,
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $service = new ContentLockService($db, $auditLogger);
        $service->forceUnlock(self::CONTENT_ID, 'admin-001');
    }

    #[Test]
    public function is_locked_returns_null_for_empty_result(): void
    {
        $this->db->method('query')->willReturn(new Result([]));

        $result = $this->service->isLocked(self::CONTENT_ID);

        self::assertNull($result);
    }

    #[Test]
    public function is_locked_returns_lock_when_active(): void
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+30 minutes');

        $this->db->method('query')->willReturn(new Result([
            new Row([
                'content_id' => self::CONTENT_ID,
                'locked_by' => self::USER_A,
                'locked_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'locale' => 'en',
            ]),
        ]));

        $lock = $this->service->isLocked(self::CONTENT_ID);

        self::assertNotNull($lock);
        self::assertSame(self::CONTENT_ID, $lock->contentId);
        self::assertSame(self::USER_A, $lock->lockedBy);
        self::assertSame('en', $lock->locale);
    }

    #[Test]
    public function is_locked_deletes_and_returns_null_for_expired_lock(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('query')
            ->willReturn(new Result([
                new Row([
                    'content_id' => self::CONTENT_ID,
                    'locked_by' => self::USER_A,
                    'locked_at' => new DateTimeImmutable('-2 hours')->format('Y-m-d H:i:s'),
                    'expires_at' => new DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'),
                    'locale' => null,
                ]),
            ]));

        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM cms_content_locks'),
                ['content_id' => self::CONTENT_ID],
            )
            ->willReturn(1);

        $service = new ContentLockService($db, $this->auditLogger);
        $result = $service->isLocked(self::CONTENT_ID);

        self::assertNull($result);
    }

    #[Test]
    public function cleanup_expired_returns_deleted_count(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(self::stringContains('DELETE FROM cms_content_locks WHERE expires_at'))
            ->willReturn(5);

        $service = new ContentLockService($db, $this->auditLogger);
        $result = $service->cleanupExpired();

        self::assertSame(5, $result);
    }
}
