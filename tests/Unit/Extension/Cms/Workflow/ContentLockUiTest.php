<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Workflow;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\ContentController;
use Pulsar\Extension\Cms\Workflow\ContentLockService;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Security\Audit\AuditEntry;

#[CoversClass(ContentLockService::class)]
#[CoversClass(ContentController::class)]
final class ContentLockUiTest extends TestCase
{
    private const string CONTENT_ID = 'content-001';
    private const string USER_ADMIN = 'admin-001';
    private const string USER_EDITOR = 'editor-001';

    private ConnectionInterface&Stub $db;
    private AuditLoggerInterface&Stub $auditLogger;
    private ContentLockService $lockService;

    protected function setUp(): void
    {
        $this->db = $this->createStub(ConnectionInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));
        $this->lockService = new ContentLockService($this->db, $this->auditLogger);
    }

    #[Test]
    public function get_lock_info_returns_lock_when_content_is_locked(): void
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+30 minutes');

        $this->db->method('query')->willReturn(new Result([
            new Row([
                'content_id' => self::CONTENT_ID,
                'locked_by' => self::USER_EDITOR,
                'locked_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'locale' => null,
            ]),
        ]));

        $lock = $this->lockService->getLockInfo(self::CONTENT_ID);

        self::assertNotNull($lock);
        self::assertSame(self::CONTENT_ID, $lock->contentId);
        self::assertSame(self::USER_EDITOR, $lock->lockedBy);
    }

    #[Test]
    public function get_lock_info_returns_null_when_content_is_not_locked(): void
    {
        $this->db->method('query')->willReturn(new Result([]));

        $lock = $this->lockService->getLockInfo(self::CONTENT_ID);

        self::assertNull($lock);
    }

    #[Test]
    public function break_lock_requires_admin_permission(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn(self::USER_EDITOR);

        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())
            ->method('denies')
            ->with($identity, 'cms.content.admin')
            ->willReturn(true);

        $controller = $this->buildController(gate: $gate);
        $request = $this->buildRequest($identity);

        $this->expectException(AuthorizationException::class);
        $controller->breakLock($request, self::CONTENT_ID);
    }

    #[Test]
    public function break_lock_calls_force_unlock(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn(self::USER_ADMIN);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $lockService = $this->createMock(ContentLockServiceInterface::class);
        $lockService->expects(self::once())
            ->method('forceUnlock')
            ->with(self::CONTENT_ID, self::USER_ADMIN);

        $controller = $this->buildController(lockService: $lockService, gate: $gate);
        $request = $this->buildRequest($identity);

        $response = $controller->breakLock($request, self::CONTENT_ID);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function break_lock_returns_success_json(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn(self::USER_ADMIN);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $lockService = $this->createStub(ContentLockServiceInterface::class);

        $controller = $this->buildController(lockService: $lockService, gate: $gate);
        $request = $this->buildRequest($identity);

        $response = $controller->breakLock($request, self::CONTENT_ID);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    private function buildController(
        ?ContentLockServiceInterface $lockService = null,
        ?GateInterface $gate = null,
    ): ContentController {
        /** @var FieldRegistryRepositoryInterface&Stub $fieldRepo */
        $fieldRepo = $this->createStub(FieldRegistryRepositoryInterface::class);

        /** @var EditorialWorkflowServiceInterface&Stub $workflowSvc */
        $workflowSvc = $this->createStub(EditorialWorkflowServiceInterface::class);

        $stateMachine = new PublishingStateMachine();

        $htmlPolicy = new SafeHtmlPolicy($this->auditLogger);

        return new ContentController(
            contentRepository: $this->createStub(ContentRepositoryInterface::class),
            translationRepository: $this->createStub(ContentTranslationRepositoryInterface::class),
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            fieldRepository: $fieldRepo,
            publishingStateMachine: $stateMachine,
            lockService: $lockService ?? $this->createStub(ContentLockServiceInterface::class),
            workflowService: $workflowSvc,
            safeHtmlPolicy: $htmlPolicy,
            gate: $gate ?? $this->createStub(GateInterface::class),
            config: CmsConfig::fromArray([]),
        );
    }

    private function buildRequest(IdentityInterface $identity): ServerRequestInterface&Stub
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => $identity,
                default => null,
            });
        $request->method('getHeaderLine')->willReturn('application/json');

        return $request;
    }
}
