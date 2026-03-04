<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\SubscriptionManagerInterface;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Http\Message\Response;
use Throwable;

use function is_string;

/**
 * Admin controller for subscription management.
 */
#[Internal]
final readonly class SubscriptionManagementController
{
    public function __construct(
        private SubscriptionManagerInterface $subscriptionManager,
    ) {}

    /**
     * POST /admin/payments/subscriptions/{id}/cancel
     *
     * Admin-initiated subscription cancellation.
     */
    public function cancel(ServerRequestInterface $request): Response
    {
        $subscriptionId = is_string($request->getAttribute('id')) ? $request->getAttribute('id') : '';

        if ($subscriptionId === '') {
            return Response::json(['error' => 'Missing subscription ID'], 400);
        }

        try {
            $subscription = $this->subscriptionManager->cancel($subscriptionId);

            return Response::json([
                'status' => 'ok',
                'subscription_id' => $subscription->id,
                'new_status' => $subscription->status->value,
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/payments/subscriptions/{id}/status
     *
     * Admin-initiated status change.
     */
    public function updateStatus(ServerRequestInterface $request): Response
    {
        $subscriptionId = is_string($request->getAttribute('id')) ? $request->getAttribute('id') : '';

        if ($subscriptionId === '') {
            return Response::json(['error' => 'Missing subscription ID'], 400);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $statusValue = is_string($body['status'] ?? null) ? $body['status'] : '';
        $status = SubscriptionStatus::tryFrom($statusValue);

        if ($status === null) {
            return Response::json(['error' => 'Invalid status'], 422);
        }

        try {
            $subscription = $this->subscriptionManager->updateStatus($subscriptionId, $status);

            return Response::json([
                'status' => 'ok',
                'subscription_id' => $subscription->id,
                'new_status' => $subscription->status->value,
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
