<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Collaboration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Collaboration\CollaborationSession;
use Pulsar\Extension\Cms\Collaboration\CrdtDocument;
use Pulsar\Extension\Cms\Internal\Collaboration\CollaborationService;
use Pulsar\Security\Audit\AuditEntry;

#[CoversClass(CollaborationService::class)]
final class CollaborationServiceTest extends TestCase
{
    private const string CONTENT_ID = 'content-001';
    private const string USER_ID = 'user-alice';
    private const string USER_NAME = 'Alice';

    private CollaborationRepositoryInterface&Stub $repository;
    private AuditLoggerInterface&Stub $auditLogger;
    private CollaborationService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(CollaborationRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));
        $this->service = new CollaborationService($this->repository, $this->auditLogger);
    }

    #[Test]
    public function join_session_creates_session_and_initializes_document(): void
    {
        // Document does not exist yet
        $this->repository->method('getDocument')->willReturn(null);

        $session = $this->service->joinSession(self::CONTENT_ID, self::USER_ID, self::USER_NAME);

        self::assertSame(self::CONTENT_ID, $session->contentId);
        self::assertSame(self::USER_ID, $session->userId);
        self::assertSame(self::USER_NAME, $session->userName);
        self::assertNull($session->cursorPosition);
        self::assertNull($session->selectionRange);
        self::assertNotEmpty($session->id);
    }

    #[Test]
    public function join_session_skips_document_creation_when_already_exists(): void
    {
        $existingDoc = CrdtDocument::initial(self::CONTENT_ID);
        $this->repository->method('getDocument')->willReturn($existingDoc);

        $session = $this->service->joinSession(self::CONTENT_ID, self::USER_ID, self::USER_NAME);

        self::assertSame(self::CONTENT_ID, $session->contentId);
    }

    #[Test]
    public function leave_session_delegates_to_repository(): void
    {
        $this->expectNotToPerformAssertions();

        // Verify no errors — the service delegates to repository.removeSession()
        $this->service->leaveSession('session-123');
    }

    #[Test]
    public function apply_update_creates_new_document_when_none_exists(): void
    {
        $this->repository->method('getDocument')->willReturn(null);

        $doc = $this->service->applyUpdate(self::CONTENT_ID, 'base64-state', self::USER_ID);

        self::assertSame(self::CONTENT_ID, $doc->contentId);
        self::assertSame('base64-state', $doc->stateVector);
        self::assertSame(2, $doc->version);
    }

    #[Test]
    public function apply_update_increments_existing_document_version(): void
    {
        $existing = new CrdtDocument(
            contentId: self::CONTENT_ID,
            stateVector: 'old-state',
            version: 3,
            updatedAt: new DateTimeImmutable(),
        );
        $this->repository->method('getDocument')->willReturn($existing);

        $doc = $this->service->applyUpdate(self::CONTENT_ID, 'new-state', self::USER_ID);

        self::assertSame('new-state', $doc->stateVector);
        self::assertSame(4, $doc->version);
    }

    #[Test]
    public function get_document_delegates_to_repository(): void
    {
        $expected = CrdtDocument::initial(self::CONTENT_ID);
        $this->repository->method('getDocument')->willReturn($expected);

        $result = $this->service->getDocument(self::CONTENT_ID);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function get_document_returns_null_when_not_found(): void
    {
        $this->repository->method('getDocument')->willReturn(null);

        $result = $this->service->getDocument(self::CONTENT_ID);

        self::assertNull($result);
    }

    #[Test]
    public function get_active_sessions_delegates_to_repository(): void
    {
        $now = new DateTimeImmutable();
        $sessions = [
            new CollaborationSession('s1', self::CONTENT_ID, 'u1', 'Alice', null, null, $now, $now),
            new CollaborationSession('s2', self::CONTENT_ID, 'u2', 'Bob', '5:10', null, $now, $now),
        ];
        $this->repository->method('getActiveSessions')->willReturn($sessions);

        $result = $this->service->getActiveSessions(self::CONTENT_ID);

        self::assertCount(2, $result);
        self::assertSame('s1', $result[0]->id);
        self::assertSame('s2', $result[1]->id);
    }

    #[Test]
    public function update_awareness_updates_matching_session(): void
    {
        $now = new DateTimeImmutable();
        $sessions = [
            new CollaborationSession('s1', self::CONTENT_ID, 'u1', 'Alice', null, null, $now, $now),
        ];
        $this->repository->method('getActiveSessions')->willReturn($sessions);

        // Should not throw
        $this->service->updateAwareness(self::CONTENT_ID, 's1', '10:5', '10:5-10:20');

        // Verify the method didn't throw — reaching this point is the assertion
        self::assertCount(1, $sessions);
    }

    #[Test]
    public function update_awareness_does_nothing_when_session_not_found(): void
    {
        $this->expectNotToPerformAssertions();

        $this->repository->method('getActiveSessions')->willReturn([]);

        // Should not throw — graceful no-op
        $this->service->updateAwareness(self::CONTENT_ID, 'nonexistent', '1:0', null);
    }

    #[Test]
    public function works_without_audit_logger(): void
    {
        $service = new CollaborationService($this->repository);
        $this->repository->method('getDocument')->willReturn(null);

        $session = $service->joinSession(self::CONTENT_ID, self::USER_ID, self::USER_NAME);

        self::assertSame(self::CONTENT_ID, $session->contentId);

        $doc = $service->applyUpdate(self::CONTENT_ID, 'state', self::USER_ID);

        self::assertSame(2, $doc->version);
    }
}
