<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\NewsletterController;
use Pulsar\Extension\Cms\Newsletter\CampaignEditorServiceInterface;
use Pulsar\Extension\Cms\Newsletter\CampaignStatus;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaign;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSend;
use Pulsar\Extension\Cms\Newsletter\NewsletterSendRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriber;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\SendStatus;
use Pulsar\Extension\Cms\Newsletter\SubscriberStatus;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(NewsletterController::class)]
final class NewsletterControllerTest extends TestCase
{
    #[Test]
    public function subscribers_returns_subscriber_list(): void
    {
        $subscriber = $this->createSubscriber('sub-1');

        $subscriberRepo = $this->createStub(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findByStatus')->willReturn(new PaginationResult(
            items: [$subscriber],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));
        $subscriberRepo->method('countByStatus')->willReturn(10);

        $controller = $this->createController(subscriberRepo: $subscriberRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->subscribers($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $subscribers */
        $subscribers = $body['subscribers'];
        self::assertCount(1, $subscribers);
        self::assertSame('sub-1', $subscribers[0]['id']);
        self::assertSame('subscriber@example.com', $subscribers[0]['email']);
        self::assertSame('confirmed', $subscribers[0]['status']);
        self::assertSame('form', $subscribers[0]['source']);
    }

    #[Test]
    public function subscribers_returns_400_for_invalid_status_filter(): void
    {
        $subscriberRepo = $this->createStub(NewsletterSubscriberRepositoryInterface::class);

        $controller = $this->createController(subscriberRepo: $subscriberRepo);
        $request = $this->createAuthenticatedRequest(queryParams: ['status' => 'invalid_status']);

        $response = $controller->subscribers($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Invalid status', $body['error']);
    }

    #[Test]
    public function subscriber_detail_returns_subscriber_with_sends(): void
    {
        $subscriber = $this->createSubscriber('sub-1');
        $send = $this->createSend('send-1', 'campaign-1', 'sub-1');

        $subscriberRepo = $this->createStub(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findById')->willReturn($subscriber);

        $sendRepo = $this->createStub(NewsletterSendRepositoryInterface::class);
        $sendRepo->method('findBySubscriberId')->willReturn([$send]);

        $controller = $this->createController(subscriberRepo: $subscriberRepo, sendRepo: $sendRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->subscriberDetail($request, 'sub-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $subscriberData */
        $subscriberData = $body['subscriber'];
        self::assertSame('sub-1', $subscriberData['id']);
        self::assertSame('subscriber@example.com', $subscriberData['email']);

        /** @var list<array<string, mixed>> $sends */
        $sends = $body['sends'];
        self::assertCount(1, $sends);
        self::assertSame('campaign-1', $sends[0]['campaign_id']);
    }

    #[Test]
    public function subscriber_detail_returns_404_when_not_found(): void
    {
        $subscriberRepo = $this->createStub(NewsletterSubscriberRepositoryInterface::class);
        $subscriberRepo->method('findById')->willReturn(null);

        $controller = $this->createController(subscriberRepo: $subscriberRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->subscriberDetail($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function campaigns_returns_campaign_list(): void
    {
        $campaign = $this->createCampaign('camp-1', CampaignStatus::Draft);

        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findAllByTenant')->willReturn(new PaginationResult(
            items: [$campaign],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = $this->createController(campaignRepo: $campaignRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->campaigns($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $campaigns */
        $campaigns = $body['campaigns'];
        self::assertCount(1, $campaigns);
        self::assertSame('camp-1', $campaigns[0]['id']);
        self::assertSame('Test Campaign', $campaigns[0]['subject']);
        self::assertSame('draft', $campaigns[0]['status']);
    }

    #[Test]
    public function campaign_form_returns_null_campaign_for_create(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->campaignForm($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['campaign']);
        self::assertFalse($body['isEdit']);
    }

    #[Test]
    public function campaign_form_returns_campaign_for_edit(): void
    {
        $campaign = $this->createCampaign('camp-1', CampaignStatus::Draft);

        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($campaign);

        $controller = $this->createController(campaignRepo: $campaignRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->campaignForm($request, 'camp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $campaignData */
        $campaignData = $body['campaign'];
        self::assertSame('camp-1', $campaignData['id']);
        self::assertSame('Test Campaign', $campaignData['subject']);
        self::assertTrue($body['isEdit']);
    }

    #[Test]
    public function campaign_form_returns_404_when_campaign_not_found(): void
    {
        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn(null);

        $controller = $this->createController(campaignRepo: $campaignRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->campaignForm($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function create_campaign_returns_201_with_valid_data(): void
    {
        $campaign = $this->createCampaign('camp-new', CampaignStatus::Draft);

        $editorService = $this->createStub(CampaignEditorServiceInterface::class);
        $editorService->method('create')->willReturn($campaign);

        $controller = $this->createController(editorService: $editorService);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'subject' => 'New Campaign',
            'body_html' => '<h1>Hello</h1>',
            'body_text' => 'Hello',
            'locale' => 'en',
        ]);

        $response = $controller->createCampaign($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('camp-new', $body['id']);
        self::assertSame('draft', $body['status']);
    }

    #[Test]
    public function create_campaign_returns_422_when_subject_empty(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'subject' => '',
            'body_html' => '<h1>Hello</h1>',
        ]);

        $response = $controller->createCampaign($request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('required', $body['error']);
    }

    #[Test]
    public function create_campaign_returns_422_when_body_html_empty(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'subject' => 'Valid Subject',
            'body_html' => '',
        ]);

        $response = $controller->createCampaign($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function update_campaign_returns_success(): void
    {
        $campaign = $this->createCampaign('camp-1', CampaignStatus::Draft);

        $editorService = $this->createStub(CampaignEditorServiceInterface::class);
        $editorService->method('update')->willReturn($campaign);

        $controller = $this->createController(editorService: $editorService);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'subject' => 'Updated Subject',
            'body_html' => '<h1>Updated</h1>',
            'locale' => 'en',
        ]);

        $response = $controller->updateCampaign($request, 'camp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('camp-1', $body['id']);
    }

    #[Test]
    public function update_campaign_returns_422_when_subject_empty(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'subject' => '',
            'body_html' => '<h1>Content</h1>',
        ]);

        $response = $controller->updateCampaign($request, 'camp-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function update_campaign_returns_422_on_service_exception(): void
    {
        $editorService = $this->createStub(CampaignEditorServiceInterface::class);
        $editorService->method('update')->willThrowException(new CmsException('Campaign not found'));

        $controller = $this->createController(editorService: $editorService);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'subject' => 'Valid Subject',
            'body_html' => '<h1>Content</h1>',
        ]);

        $response = $controller->updateCampaign($request, 'nonexistent');

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Campaign not found', $body['error']);
    }

    #[Test]
    public function delete_campaign_returns_success(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->deleteCampaign($request, 'camp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_campaign_returns_422_on_service_exception(): void
    {
        $editorService = $this->createStub(CampaignEditorServiceInterface::class);
        $editorService->method('delete')->willThrowException(new CmsException('Cannot delete sent campaign'));

        $controller = $this->createController(editorService: $editorService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->deleteCampaign($request, 'camp-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function send_campaign_returns_success_for_draft(): void
    {
        $draftCampaign = $this->createCampaign('camp-1', CampaignStatus::Draft);
        $scheduledCampaign = $this->createCampaign('camp-1', CampaignStatus::Scheduled);

        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($draftCampaign);

        $editorService = $this->createStub(CampaignEditorServiceInterface::class);
        $editorService->method('schedule')->willReturn($scheduledCampaign);

        $controller = $this->createController(campaignRepo: $campaignRepo, editorService: $editorService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->sendCampaign($request, 'camp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('camp-1', $body['id']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('queued', $body['message']);
    }

    #[Test]
    public function send_campaign_returns_404_when_not_found(): void
    {
        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn(null);

        $controller = $this->createController(campaignRepo: $campaignRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->sendCampaign($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function send_campaign_returns_422_when_already_sent(): void
    {
        $sentCampaign = $this->createCampaign('camp-1', CampaignStatus::Sent);

        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($sentCampaign);

        $controller = $this->createController(campaignRepo: $campaignRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->sendCampaign($request, 'camp-1');

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Draft or Scheduled', $body['error']);
    }

    #[Test]
    public function campaign_analytics_returns_analytics_data(): void
    {
        $campaign = $this->createCampaign('camp-1', CampaignStatus::Sent, recipientCount: 100, openedCount: 40, clickedCount: 10, bouncedCount: 5);

        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn($campaign);

        $sendRepo = $this->createStub(NewsletterSendRepositoryInterface::class);
        $sendRepo->method('countByCampaignAndStatus')->willReturnCallback(
            static fn(string $id, SendStatus $status): int => match ($status) {
                SendStatus::Sent => 95,
                SendStatus::Delivered => 90,
                SendStatus::Bounced => 5,
                SendStatus::Failed => 0,
                default => 0,
            },
        );

        $controller = $this->createController(campaignRepo: $campaignRepo, sendRepo: $sendRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->campaignAnalytics($request, 'camp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $campaignData */
        $campaignData = $body['campaign'];
        self::assertSame('camp-1', $campaignData['id']);
        self::assertSame('sent', $campaignData['status']);

        /** @var array<string, mixed> $analytics */
        $analytics = $body['analytics'];
        self::assertSame(100, $analytics['recipient_count']);
        self::assertSame(95, $analytics['sent_count']);
        self::assertSame(90, $analytics['delivered_count']);
        self::assertSame(5, $analytics['bounced_count']);
        self::assertSame(40, $analytics['opened_count']);
        self::assertSame(10, $analytics['clicked_count']);
        self::assertEqualsWithDelta(40.0, $analytics['open_rate'], 0.01);
        self::assertEqualsWithDelta(10.0, $analytics['click_rate'], 0.01);
        self::assertEqualsWithDelta(5.0, $analytics['bounce_rate'], 0.01);
    }

    #[Test]
    public function campaign_analytics_returns_404_when_not_found(): void
    {
        $campaignRepo = $this->createStub(NewsletterCampaignRepositoryInterface::class);
        $campaignRepo->method('findById')->willReturn(null);

        $controller = $this->createController(campaignRepo: $campaignRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->campaignAnalytics($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function subscribers_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->subscribers($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function subscribers_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->subscribers($request);
    }

    private function createController(
        ?NewsletterSubscriberRepositoryInterface $subscriberRepo = null,
        ?NewsletterCampaignRepositoryInterface $campaignRepo = null,
        ?NewsletterSendRepositoryInterface $sendRepo = null,
        ?CampaignEditorServiceInterface $editorService = null,
        ?GateInterface $gate = null,
    ): NewsletterController {
        return new NewsletterController(
            subscriberRepository: $subscriberRepo ?? $this->createStub(NewsletterSubscriberRepositoryInterface::class),
            campaignRepository: $campaignRepo ?? $this->createStub(NewsletterCampaignRepositoryInterface::class),
            sendRepository: $sendRepo ?? $this->createStub(NewsletterSendRepositoryInterface::class),
            campaignEditorService: $editorService ?? $this->createStub(CampaignEditorServiceInterface::class),
            gate: $gate,
        );
    }

    private function createSubscriber(string $id): NewsletterSubscriber
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new NewsletterSubscriber(
            id: $id,
            email: 'subscriber@example.com',
            userId: null,
            locale: 'en',
            status: SubscriberStatus::Confirmed,
            confirmTokenHash: null,
            confirmedAt: $now,
            unsubscribedAt: null,
            ipAddressHash: 'hashed_ip',
            source: 'form',
            tenantId: null,
            createdAt: $now,
        );
    }

    private function createCampaign(
        string $id,
        CampaignStatus $status,
        int $recipientCount = 0,
        int $openedCount = 0,
        int $clickedCount = 0,
        int $bouncedCount = 0,
    ): NewsletterCampaign {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new NewsletterCampaign(
            id: $id,
            tenantId: null,
            subject: 'Test Campaign',
            bodyHtml: '<h1>Hello</h1>',
            bodyText: 'Hello',
            locale: 'en',
            status: $status,
            scheduledAt: null,
            sentAt: $status === CampaignStatus::Sent ? $now : null,
            recipientCount: $recipientCount,
            openedCount: $openedCount,
            clickedCount: $clickedCount,
            bouncedCount: $bouncedCount,
            createdBy: 'admin-1',
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function createSend(string $id, string $campaignId, string $subscriberId): NewsletterSend
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new NewsletterSend(
            id: $id,
            campaignId: $campaignId,
            subscriberId: $subscriberId,
            status: SendStatus::Delivered,
            sentAt: $now,
            openedAt: $now,
            clickedAt: null,
            bounceReason: null,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        ?array $parsedBody = null,
        array $queryParams = [],
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/newsletter');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => false,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/newsletter');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
