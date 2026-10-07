<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Newsletter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Newsletter\CampaignEditorService;
use Pulsar\Extension\Cms\Newsletter\CampaignStatus;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaign;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Mail\MailManagerInterface;

#[CoversClass(CampaignEditorService::class)]
final class CampaignEditorServiceTest extends TestCase
{
    private NewsletterCampaignRepositoryInterface&Stub $campaignRepo;
    private NewsletterSubscriberRepositoryInterface&Stub $subscriberRepo;
    private MailManagerInterface&Stub $mailManager;
    private AuditLoggerInterface&Stub $auditLogger;
    private CampaignEditorService $service;

    protected function setUp(): void
    {
        $this->campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $this->subscriberRepo = $this->createStub(NewsletterSubscriberRepositoryInterface::class);
        $this->mailManager = $this->createStub(MailManagerInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->service = new CampaignEditorService(
            $this->campaignRepo,
            $this->subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );
    }

    #[Test]
    public function createCampaignReturnsDraftWithCorrectFields(): void
    {
        /** @var NewsletterCampaignRepositoryInterface&MockObject $campaignRepo */
        $campaignRepo = $this->createMock(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->expects(self::once())->method('save');

        $service = new CampaignEditorService(
            $campaignRepo,
            $this->subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $campaign = $service->create(
            subject: 'Welcome Newsletter',
            bodyHtml: '<h1>Welcome</h1>',
            locale: 'en',
            bodyText: 'Welcome',
            tenantId: 'tenant-1',
            createdBy: 'user-1',
        );

        self::assertSame(CampaignStatus::Draft, $campaign->status);
        self::assertSame('Welcome Newsletter', $campaign->subject);
        self::assertSame('<h1>Welcome</h1>', $campaign->bodyHtml);
        self::assertSame('Welcome', $campaign->bodyText);
        self::assertSame('en', $campaign->locale);
        self::assertSame('tenant-1', $campaign->tenantId);
        self::assertSame('user-1', $campaign->createdBy);
        self::assertSame(0, $campaign->recipientCount);
    }

    #[Test]
    public function updateDraftCampaignPreservesIdAndUpdatesFields(): void
    {
        $draft = $this->buildCampaign(CampaignStatus::Draft);

        /** @var NewsletterCampaignRepositoryInterface&MockObject $campaignRepo */
        $campaignRepo = $this->createMock(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($draft);
        $campaignRepo->expects(self::once())->method('save');

        $service = new CampaignEditorService(
            $campaignRepo,
            $this->subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $updated = $service->update(
            campaignId: 'camp-001',
            subject: 'Updated Subject',
            bodyHtml: '<h1>Updated</h1>',
            bodyText: 'Updated text',
            locale: 'fr',
        );

        self::assertSame('camp-001', $updated->id);
        self::assertSame('Updated Subject', $updated->subject);
        self::assertSame('<h1>Updated</h1>', $updated->bodyHtml);
        self::assertSame('fr', $updated->locale);
    }

    #[Test]
    public function updateNonDraftCampaignThrowsException(): void
    {
        $scheduled = $this->buildCampaign(CampaignStatus::Scheduled);
        $this->campaignRepo->method('findById')->willReturn($scheduled);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/only.*edited.*Draft/i');

        $this->service->update('camp-001', 'Subject', '<p>Body</p>', null, 'en');
    }

    #[Test]
    public function scheduleDraftCampaignTransitionsToScheduled(): void
    {
        $draft = $this->buildCampaign(CampaignStatus::Draft);

        /** @var NewsletterCampaignRepositoryInterface&MockObject $campaignRepo */
        $campaignRepo = $this->createMock(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($draft);
        $campaignRepo->expects(self::once())->method('save');

        $service = new CampaignEditorService(
            $campaignRepo,
            $this->subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $scheduledAt = new DateTimeImmutable('+1 day');
        $scheduled = $service->schedule('camp-001', $scheduledAt);

        self::assertSame(CampaignStatus::Scheduled, $scheduled->status);
        self::assertSame($scheduledAt, $scheduled->scheduledAt);
    }

    #[Test]
    public function scheduleNonDraftCampaignThrowsException(): void
    {
        $sent = $this->buildCampaign(CampaignStatus::Sent);
        $this->campaignRepo->method('findById')->willReturn($sent);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/only.*scheduled.*Draft/i');

        $this->service->schedule('camp-001', new DateTimeImmutable('+1 day'));
    }

    #[Test]
    public function cancelScheduledCampaignTransitionsToCancelled(): void
    {
        $scheduled = $this->buildCampaign(CampaignStatus::Scheduled);

        /** @var NewsletterCampaignRepositoryInterface&MockObject $campaignRepo */
        $campaignRepo = $this->createMock(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($scheduled);
        $campaignRepo->expects(self::once())->method('save');

        $service = new CampaignEditorService(
            $campaignRepo,
            $this->subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $cancelled = $service->cancel('camp-001');

        self::assertSame(CampaignStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function cancelNonScheduledCampaignThrowsException(): void
    {
        $draft = $this->buildCampaign(CampaignStatus::Draft);
        $this->campaignRepo->method('findById')->willReturn($draft);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/only.*cancelled.*Scheduled/i');

        $this->service->cancel('camp-001');
    }

    #[Test]
    public function deleteDraftCampaignSucceeds(): void
    {
        $draft = $this->buildCampaign(CampaignStatus::Draft);

        /** @var NewsletterCampaignRepositoryInterface&MockObject $campaignRepo */
        $campaignRepo = $this->createMock(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($draft);
        $campaignRepo->expects(self::once())->method('delete');

        $service = new CampaignEditorService(
            $campaignRepo,
            $this->subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $service->delete('camp-001');
    }

    #[Test]
    public function deleteSentCampaignThrowsException(): void
    {
        $sent = $this->buildCampaign(CampaignStatus::Sent);
        $this->campaignRepo->method('findById')->willReturn($sent);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/only.*deleted.*Draft.*Cancelled/i');

        $this->service->delete('camp-001');
    }

    #[Test]
    public function operationsOnNonexistentCampaignThrow(): void
    {
        $this->campaignRepo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->service->update('nonexistent', 'Sub', '<p>B</p>', null, 'en');
    }

    #[Test]
    public function getRecipientCountDelegatesToSubscriberRepository(): void
    {
        $campaign = $this->buildCampaign(CampaignStatus::Draft);
        $this->campaignRepo->method('findById')->willReturn($campaign);
        $this->subscriberRepo->method('findAllConfirmed')->willReturn(['s1', 's2', 's3']);

        $count = $this->service->getRecipientCount('camp-001');

        self::assertSame(3, $count);
    }

    private function buildCampaign(CampaignStatus $status): NewsletterCampaign
    {
        $now = new DateTimeImmutable();

        return new NewsletterCampaign(
            id: 'camp-001',
            tenantId: null,
            subject: 'Test Campaign',
            bodyHtml: '<p>Body</p>',
            bodyText: 'Body',
            locale: 'en',
            status: $status,
            scheduledAt: $status === CampaignStatus::Scheduled ? new DateTimeImmutable('+1 day') : null,
            sentAt: $status === CampaignStatus::Sent ? $now : null,
            recipientCount: 0,
            openedCount: 0,
            clickedCount: 0,
            bouncedCount: 0,
            createdBy: 'user-1',
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
