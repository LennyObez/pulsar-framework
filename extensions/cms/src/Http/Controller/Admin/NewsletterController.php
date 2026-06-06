<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Newsletter\CampaignEditorServiceInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaign;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSendRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriber;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\SendStatus;
use Pulsar\Extension\Cms\Newsletter\SubscriberStatus;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;
use function max;
use function min;
use function round;

/**
 * Admin controller for newsletter management.
 *
 * Provides subscriber listing, campaign CRUD, scheduling, sending,
 * and analytics views for the CMS admin panel.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class NewsletterController extends AbstractAdminController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private NewsletterSubscriberRepositoryInterface $subscriberRepository,
        private NewsletterCampaignRepositoryInterface $campaignRepository,
        private NewsletterSendRepositoryInterface $sendRepository,
        private CampaignEditorServiceInterface $campaignEditorService,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * GET /admin/cms/newsletter/subscribers: List subscribers with status filter.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function subscribers(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        $params = $request->getQueryParams();
        /** @var mixed $rawStatus */
        $rawStatus = $params['status'] ?? null;
        $statusFilter = is_string($rawStatus) ? $rawStatus : null;

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($statusFilter !== null) {
            $status = SubscriberStatus::tryFrom($statusFilter);

            if ($status === null) {
                return Response::json(['error' => 'Invalid status filter'], 400);
            }

            $result = $this->subscriberRepository->findByStatus($status, $tenantId, $page, $perPage);
        } else {
            $result = $this->subscriberRepository->findByStatus(
                SubscriberStatus::Confirmed,
                $tenantId,
                $page,
                $perPage,
            );
        }

        $statusCounts = [
            'confirmed' => $this->subscriberRepository->countByStatus(SubscriberStatus::Confirmed, $tenantId),
            'pending' => $this->subscriberRepository->countByStatus(SubscriberStatus::Pending, $tenantId),
            'unsubscribed' => $this->subscriberRepository->countByStatus(SubscriberStatus::Unsubscribed, $tenantId),
        ];

        $data = [
            'subscribers' => array_map(static fn(NewsletterSubscriber $s) => [
                'id' => $s->id,
                'email' => $s->email,
                'locale' => $s->locale,
                'status' => $s->status->value,
                'source' => $s->source,
                'confirmed_at' => $s->confirmedAt?->format('c'),
                'unsubscribed_at' => $s->unsubscribedAt?->format('c'),
                'created_at' => $s->createdAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
            'statusCounts' => $statusCounts,
            'activeStatus' => $statusFilter ?? 'confirmed',
        ];

        return $this->respondWithView($request, 'admin.newsletter.subscribers', $data);
    }

    /**
     * GET /admin/cms/newsletter/subscribers/{id}: Subscriber detail.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function subscriberDetail(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        $subscriber = $this->subscriberRepository->findById($id);

        if ($subscriber === null) {
            return Response::json(['error' => 'Subscriber not found'], 404);
        }

        $sends = $this->sendRepository->findBySubscriberId($id);

        $data = [
            'subscriber' => [
                'id' => $subscriber->id,
                'email' => $subscriber->email,
                'user_id' => $subscriber->userId,
                'locale' => $subscriber->locale,
                'status' => $subscriber->status->value,
                'source' => $subscriber->source,
                'confirmed_at' => $subscriber->confirmedAt?->format('c'),
                'unsubscribed_at' => $subscriber->unsubscribedAt?->format('c'),
                'created_at' => $subscriber->createdAt->format('c'),
            ],
            'sends' => array_map(static fn($s) => [
                'campaign_id' => $s->campaignId,
                'status' => $s->status->value,
                'sent_at' => $s->sentAt?->format('c'),
                'opened_at' => $s->openedAt?->format('c'),
                'clicked_at' => $s->clickedAt?->format('c'),
            ], $sends),
        ];

        return $this->respondWithView($request, 'admin.newsletter.subscriber-detail', $data);
    }

    /**
     * GET /admin/cms/newsletter/campaigns: List campaigns.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function campaigns(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        $params = $request->getQueryParams();

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->campaignRepository->findAllByTenant($tenantId, $page, $perPage);

        $data = [
            'campaigns' => array_map(static fn(NewsletterCampaign $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'locale' => $c->locale,
                'status' => $c->status->value,
                'recipient_count' => $c->recipientCount,
                'opened_count' => $c->openedCount,
                'clicked_count' => $c->clickedCount,
                'bounced_count' => $c->bouncedCount,
                'scheduled_at' => $c->scheduledAt?->format('c'),
                'sent_at' => $c->sentAt?->format('c'),
                'created_at' => $c->createdAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
        ];

        return $this->respondWithView($request, 'admin.newsletter.campaigns', $data);
    }

    /**
     * GET /admin/cms/newsletter/campaigns/create: Show campaign creation form.
     * GET /admin/cms/newsletter/campaigns/{id}/edit: Show campaign edit form.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function campaignForm(ServerRequestInterface $request, ?string $id = null): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        $campaign = null;

        if ($id !== null) {
            $campaign = $this->campaignRepository->findById($id);

            if ($campaign === null) {
                return Response::json(['error' => 'Campaign not found'], 404);
            }
        }

        $data = [
            'campaign' => $campaign !== null ? [
                'id' => $campaign->id,
                'subject' => $campaign->subject,
                'body_html' => $campaign->bodyHtml,
                'body_text' => $campaign->bodyText,
                'locale' => $campaign->locale,
                'status' => $campaign->status->value,
            ] : null,
            'isEdit' => $campaign !== null,
        ];

        return $this->respondWithView($request, 'admin.newsletter.campaign-form', $data);
    }

    /**
     * POST /admin/cms/newsletter/campaigns: Create a new campaign.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function createCampaign(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawSubject */
        $rawSubject = $body['subject'] ?? null;
        $subject = is_string($rawSubject) ? $rawSubject : '';
        /** @var mixed $rawBodyHtml */
        $rawBodyHtml = $body['body_html'] ?? null;
        $bodyHtml = is_string($rawBodyHtml) ? $rawBodyHtml : '';
        /** @var mixed $rawBodyText */
        $rawBodyText = $body['body_text'] ?? null;
        $bodyText = is_string($rawBodyText) ? $rawBodyText : null;
        /** @var mixed $rawLocale */
        $rawLocale = $body['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : 'en';

        if ($subject === '' || $bodyHtml === '') {
            return Response::json(['error' => 'Subject and HTML body are required'], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $campaign = $this->campaignEditorService->create(
            $subject,
            $bodyHtml,
            $locale,
            $bodyText,
            $tenantId,
            $identity->id(),
        );

        return Response::json([
            'id' => $campaign->id,
            'status' => $campaign->status->value,
        ], 201);
    }

    /**
     * PUT /admin/cms/newsletter/campaigns/{id}: Update a campaign.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function updateCampaign(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawSubject */
        $rawSubject = $body['subject'] ?? null;
        $subject = is_string($rawSubject) ? $rawSubject : '';
        /** @var mixed $rawBodyHtml */
        $rawBodyHtml = $body['body_html'] ?? null;
        $bodyHtml = is_string($rawBodyHtml) ? $rawBodyHtml : '';
        /** @var mixed $rawBodyText */
        $rawBodyText = $body['body_text'] ?? null;
        $bodyText = is_string($rawBodyText) ? $rawBodyText : null;
        /** @var mixed $rawLocale */
        $rawLocale = $body['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : 'en';

        if ($subject === '' || $bodyHtml === '') {
            return Response::json(['error' => 'Subject and HTML body are required'], 422);
        }

        try {
            $campaign = $this->campaignEditorService->update($id, $subject, $bodyHtml, $bodyText, $locale);

            return Response::json([
                'id' => $campaign->id,
                'status' => $campaign->status->value,
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /admin/cms/newsletter/campaigns/{id}: Delete a campaign.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function deleteCampaign(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        try {
            $this->campaignEditorService->delete($id);

            return Response::json(['status' => 'deleted']);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/cms/newsletter/campaigns/{id}/send: Send a campaign immediately.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function sendCampaign(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        $campaign = $this->campaignRepository->findById($id);

        if ($campaign === null) {
            return Response::json(['error' => 'Campaign not found'], 404);
        }

        if (!$campaign->isDraft() && !$campaign->isScheduled()) {
            return Response::json(['error' => 'Campaign must be in Draft or Scheduled status to send'], 422);
        }

        // Get the scheduled version if it is scheduled, or schedule for now if draft
        try {
            if ($campaign->isDraft()) {
                $campaign = $this->campaignEditorService->schedule($id, new DateTimeImmutable());
            }

            return Response::json([
                'id' => $campaign->id,
                'status' => $campaign->status->value,
                'message' => 'Campaign has been queued for sending',
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /admin/cms/newsletter/campaigns/{id}/analytics: Campaign analytics.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function campaignAnalytics(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.newsletter.manage');

        $campaign = $this->campaignRepository->findById($id);

        if ($campaign === null) {
            return Response::json(['error' => 'Campaign not found'], 404);
        }

        $sentCount = $this->sendRepository->countByCampaignAndStatus($id, SendStatus::Sent);
        $deliveredCount = $this->sendRepository->countByCampaignAndStatus($id, SendStatus::Delivered);
        $bouncedCount = $this->sendRepository->countByCampaignAndStatus($id, SendStatus::Bounced);
        $failedCount = $this->sendRepository->countByCampaignAndStatus($id, SendStatus::Failed);

        $recipientCount = $campaign->recipientCount > 0 ? $campaign->recipientCount : 1;

        $data = [
            'campaign' => [
                'id' => $campaign->id,
                'subject' => $campaign->subject,
                'status' => $campaign->status->value,
                'sent_at' => $campaign->sentAt?->format('c'),
            ],
            'analytics' => [
                'recipient_count' => $campaign->recipientCount,
                'sent_count' => $sentCount,
                'delivered_count' => $deliveredCount,
                'bounced_count' => $bouncedCount,
                'failed_count' => $failedCount,
                'opened_count' => $campaign->openedCount,
                'clicked_count' => $campaign->clickedCount,
                'open_rate' => round((float) $campaign->openedCount / (float) $recipientCount * 100.0, 2),
                'click_rate' => round((float) $campaign->clickedCount / (float) $recipientCount * 100.0, 2),
                'bounce_rate' => round((float) $campaign->bouncedCount / (float) $recipientCount * 100.0, 2),
            ],
        ];

        return $this->respondWithView($request, 'admin.newsletter.analytics', $data);
    }
}
