<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Newsletter;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Newsletter\NewsletterSendRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriptionServiceInterface;
use Pulsar\Extension\Cms\Newsletter\SendStatus;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;

/**
 * Webhook controller for processing email bounce notifications.
 *
 * Receives bounce events from mail providers, updates send record statuses,
 * and auto-unsubscribes addresses after 3 hard bounces to protect sender
 * reputation.
 */
#[Internal(reason: 'CMS newsletter controller; implementation detail')]
final readonly class BounceWebhookController
{
    private const int HARD_BOUNCE_THRESHOLD = 3;

    public function __construct(
        private NewsletterSendRepositoryInterface $sendRepository,
        private NewsletterSubscriberRepositoryInterface $subscriberRepository,
        private NewsletterSubscriptionServiceInterface $subscriptionService,
    ) {}

    /**
     * POST /webhook/newsletter/bounce
     *
     * Processes bounce notification. Expected JSON payload:
     * {
     *   "send_id": "...",
     *   "subscriber_id": "...",
     *   "bounce_type": "hard|soft",
     *   "reason": "..."
     * }
     */
    public function handle(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $sendId = is_string($body['send_id'] ?? null) ? $body['send_id'] : '';
        $subscriberId = is_string($body['subscriber_id'] ?? null) ? $body['subscriber_id'] : '';
        $bounceType = is_string($body['bounce_type'] ?? null) ? $body['bounce_type'] : 'soft';
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : 'Unknown bounce';

        if ($sendId === '' || $subscriberId === '') {
            return Response::json(['error' => 'send_id and subscriber_id are required'], 400);
        }

        // Update the send record status
        $this->sendRepository->updateStatus($sendId, SendStatus::Bounced, $reason);

        // For hard bounces, check if auto-unsubscribe threshold is reached
        if ($bounceType === 'hard') {
            $this->checkHardBounceThreshold($subscriberId);
        }

        return Response::json(['status' => 'processed']);
    }

    /**
     * POST /webhook/newsletter/bounce/batch
     *
     * Processes multiple bounce notifications. Expected JSON payload:
     * {
     *   "bounces": [
     *     { "send_id": "...", "subscriber_id": "...", "bounce_type": "hard", "reason": "..." },
     *     ...
     *   ]
     * }
     */
    public function handleBatch(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $bounces = is_array($body['bounces'] ?? null) ? $body['bounces'] : [];
        $processed = 0;

        foreach ($bounces as $bounce) {
            if (!is_array($bounce)) {
                continue;
            }

            $sendId = is_string($bounce['send_id'] ?? null) ? $bounce['send_id'] : '';
            $subscriberId = is_string($bounce['subscriber_id'] ?? null) ? $bounce['subscriber_id'] : '';
            $bounceType = is_string($bounce['bounce_type'] ?? null) ? $bounce['bounce_type'] : 'soft';
            $reason = is_string($bounce['reason'] ?? null) ? $bounce['reason'] : 'Unknown bounce';

            if ($sendId === '' || $subscriberId === '') {
                continue;
            }

            $this->sendRepository->updateStatus($sendId, SendStatus::Bounced, $reason);

            if ($bounceType === 'hard') {
                $this->checkHardBounceThreshold($subscriberId);
            }

            $processed++;
        }

        return Response::json(['status' => 'processed', 'count' => $processed]);
    }

    /**
     * Check if a subscriber has exceeded the hard bounce threshold
     * and auto-unsubscribe if so.
     */
    private function checkHardBounceThreshold(string $subscriberId): void
    {
        $sends = $this->sendRepository->findBySubscriberId($subscriberId);

        $hardBounceCount = 0;

        foreach ($sends as $send) {
            if ($send->status === SendStatus::Bounced) {
                $hardBounceCount++;
            }
        }

        if ($hardBounceCount >= self::HARD_BOUNCE_THRESHOLD) {
            $subscriber = $this->subscriberRepository->findById($subscriberId);

            if ($subscriber !== null && $subscriber->isConfirmed()) {
                $this->subscriptionService->unsubscribe($subscriberId);
            }
        }
    }
}
