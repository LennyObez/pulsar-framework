<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Newsletter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Newsletter\NewsletterSubscriptionService;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriber;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\SubscriberStatus;
use Pulsar\Mail\MailManagerInterface;

use function bin2hex;
use function sodium_crypto_generichash;

#[CoversClass(NewsletterSubscriptionService::class)]
final class NewsletterSubscriptionServiceTest extends TestCase
{
    private NewsletterSubscriberRepositoryInterface&Stub $subscriberRepo;
    private MailManagerInterface&Stub $mailManager;
    private AuditLoggerInterface&Stub $auditLogger;
    private NewsletterSubscriptionService $service;

    protected function setUp(): void
    {
        $this->subscriberRepo = $this->createStub(NewsletterSubscriberRepositoryInterface::class);
        $this->mailManager = $this->createStub(MailManagerInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->service = new NewsletterSubscriptionService(
            $this->subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );
    }

    #[Test]
    public function subscribeWithNewEmailSavesSubscriberAndSendsConfirmation(): void
    {
        /** @var NewsletterSubscriberRepositoryInterface&MockObject $subscriberRepo */
        $subscriberRepo = $this->createMock(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findByEmail')->willReturn(null);
        $subscriberRepo->expects(self::once())->method('save');

        $service = new NewsletterSubscriptionService(
            $subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $result = $service->subscribe(
            email: 'alice@example.com',
            locale: 'en',
            source: 'form',
            ipAddress: '127.0.0.1',
        );

        self::assertSame('alice@example.com', $result->email);
        self::assertSame('en', $result->locale);
        self::assertSame(SubscriberStatus::Pending, $result->status);
        self::assertNotNull($result->confirmTokenHash);
    }

    #[Test]
    public function subscribeWithAlreadyConfirmedEmailThrowsException(): void
    {
        $confirmed = $this->buildSubscriber(SubscriberStatus::Confirmed);
        $this->subscriberRepo->method('findByEmail')->willReturn($confirmed);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/already.*confirmed/i');

        $this->service->subscribe(
            email: 'alice@example.com',
            locale: 'en',
            source: 'form',
            ipAddress: '127.0.0.1',
        );
    }

    #[Test]
    public function subscribeWithPendingEmailResendsConfirmation(): void
    {
        $pending = $this->buildSubscriber(SubscriberStatus::Pending);

        /** @var NewsletterSubscriberRepositoryInterface&MockObject $subscriberRepo */
        $subscriberRepo = $this->createMock(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findByEmail')->willReturn($pending);
        $subscriberRepo->expects(self::once())->method('save');

        $service = new NewsletterSubscriptionService(
            $subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $result = $service->subscribe(
            email: 'alice@example.com',
            locale: 'en',
            source: 'form',
            ipAddress: '127.0.0.1',
        );

        self::assertSame(SubscriberStatus::Pending, $result->status);
        self::assertNotNull($result->confirmTokenHash);
    }

    #[Test]
    public function subscribeWithUnsubscribedEmailResubscribes(): void
    {
        $unsubscribed = $this->buildSubscriber(SubscriberStatus::Unsubscribed);

        /** @var NewsletterSubscriberRepositoryInterface&MockObject $subscriberRepo */
        $subscriberRepo = $this->createMock(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findByEmail')->willReturn($unsubscribed);
        $subscriberRepo->expects(self::once())->method('save');

        $service = new NewsletterSubscriptionService(
            $subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $result = $service->subscribe(
            email: 'alice@example.com',
            locale: 'en',
            source: 'form',
            ipAddress: '127.0.0.1',
        );

        self::assertSame(SubscriberStatus::Pending, $result->status);
    }

    #[Test]
    public function confirmWithMatchingTokenReturnsTrue(): void
    {
        $rawToken = 'abc123def456';
        $tokenHash = bin2hex(sodium_crypto_generichash($rawToken));

        $pending = new NewsletterSubscriber(
            id: 'sub-001',
            email: 'alice@example.com',
            userId: null,
            locale: 'en',
            status: SubscriberStatus::Pending,
            confirmTokenHash: $tokenHash,
            confirmedAt: null,
            unsubscribedAt: null,
            ipAddressHash: bin2hex(sodium_crypto_generichash('127.0.0.1')),
            source: 'form',
            tenantId: null,
            createdAt: new DateTimeImmutable(),
        );

        /** @var NewsletterSubscriberRepositoryInterface&MockObject $subscriberRepo */
        $subscriberRepo = $this->createMock(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findByStatus')->willReturn(
            new PaginationResult(items: [$pending], total: 1, hasMore: false, perPage: 20),
        );
        $subscriberRepo->expects(self::once())->method('save');

        $service = new NewsletterSubscriptionService(
            $subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $result = $service->confirm($rawToken);

        self::assertTrue($result);
    }

    #[Test]
    public function confirmWithNoMatchingTokenReturnsFalse(): void
    {
        $this->subscriberRepo->method('findByStatus')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20),
        );

        $result = $this->service->confirm('nonexistent-token');

        self::assertFalse($result);
    }

    #[Test]
    public function unsubscribeExistingSubscriberReturnsTrue(): void
    {
        $confirmed = $this->buildSubscriber(SubscriberStatus::Confirmed);

        /** @var NewsletterSubscriberRepositoryInterface&MockObject $subscriberRepo */
        $subscriberRepo = $this->createMock(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findById')->willReturn($confirmed);
        $subscriberRepo->expects(self::once())->method('save');

        $service = new NewsletterSubscriptionService(
            $subscriberRepo,
            $this->mailManager,
            $this->auditLogger,
        );

        $result = $service->unsubscribe('sub-001');

        self::assertTrue($result);
    }

    #[Test]
    public function unsubscribeNonexistentSubscriberThrowsException(): void
    {
        $this->subscriberRepo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->service->unsubscribe('nonexistent');
    }

    private function buildSubscriber(SubscriberStatus $status): NewsletterSubscriber
    {
        return new NewsletterSubscriber(
            id: 'sub-001',
            email: 'alice@example.com',
            userId: null,
            locale: 'en',
            status: $status,
            confirmTokenHash: $status === SubscriberStatus::Pending ? 'some-hash' : null,
            confirmedAt: $status === SubscriberStatus::Confirmed ? new DateTimeImmutable() : null,
            unsubscribedAt: $status === SubscriberStatus::Unsubscribed ? new DateTimeImmutable() : null,
            ipAddressHash: bin2hex(sodium_crypto_generichash('127.0.0.1')),
            source: 'form',
            tenantId: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
