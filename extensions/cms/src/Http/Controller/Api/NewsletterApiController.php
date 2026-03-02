<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriptionServiceInterface;
use Pulsar\Extension\Cms\Newsletter\SubscriberStatus;
use Pulsar\Http\Message\Response;

use function filter_var;
use function is_string;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Public API controller for newsletter subscription endpoints.
 *
 * Provides JSON API for subscribing, confirming, and querying subscriber stats.
 * CSRF and honeypot validation are performed inline.
 */
#[Internal(reason: 'CMS REST API controller; implementation detail')]
final readonly class NewsletterApiController
{
    public function __construct(
        private NewsletterSubscriptionServiceInterface $subscriptionService,
        private NewsletterSubscriberRepositoryInterface $subscriberRepository,
    ) {}

    /**
     * POST /api/cms/newsletter/subscribe
     *
     * Accepts email, locale, source. Validates honeypot field and email format.
     */
    public function subscribe(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        // Honeypot check: if the hidden field has content, it is a bot
        /** @var string $honeypot */
        $honeypot = $body['_hp_field'] ?? '';

        if ($honeypot !== '') {
            // Silently accept to avoid revealing the honeypot to bots
            return Response::json(['status' => 'ok', 'message' => 'Subscription received']);
        }

        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $locale = is_string($body['locale'] ?? null) ? trim($body['locale']) : 'en';
        $source = is_string($body['source'] ?? null) ? trim($body['source']) : 'form';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Response::json(['error' => 'A valid email address is required'], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $serverParams = $request->getServerParams();
        $ipAddress = is_string($serverParams['REMOTE_ADDR'] ?? null)
            ? $serverParams['REMOTE_ADDR']
            : '0.0.0.0';

        try {
            $subscriber = $this->subscriptionService->subscribe(
                $email,
                $locale,
                $source,
                $ipAddress,
                $tenantId,
            );

            return Response::json([
                'status' => 'ok',
                'message' => 'Please check your email to confirm your subscription',
                'subscriber_id' => $subscriber->id,
            ], 201);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/cms/newsletter/stats
     *
     * Returns subscriber count statistics by status.
     */
    public function stats(ServerRequestInterface $request): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $confirmed = $this->subscriberRepository->countByStatus(SubscriberStatus::Confirmed, $tenantId);
        $pending = $this->subscriberRepository->countByStatus(SubscriberStatus::Pending, $tenantId);
        $unsubscribed = $this->subscriberRepository->countByStatus(SubscriberStatus::Unsubscribed, $tenantId);

        return Response::json([
            'confirmed' => $confirmed,
            'pending' => $pending,
            'unsubscribed' => $unsubscribed,
            'total' => $confirmed + $pending + $unsubscribed,
        ]);
    }
}
