<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_string;

/**
 * REST API controller for subscription verification and status queries.
 *
 * All endpoints require a valid Bearer token (enforced by SubscriptionTokenGuard).
 * The authenticated user_id is read from a request attribute.
 */
#[Internal(reason: 'HTTP controller; implementation detail')]
final readonly class SubscriptionController
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    /**
     * POST /api/v1/subscriptions/verify
     *
     * Verifies a purchase token with the store and creates/updates the subscription.
     *
     * Expected JSON body:
     *   { "store": "google"|"apple", "purchase_token": "...", "plan": "..." }
     */
    public function verify(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawStore */
        $rawStore = $body['store'] ?? null;
        $storeValue = is_string($rawStore) ? $rawStore : '';
        /** @var mixed $rawPurchaseToken */
        $rawPurchaseToken = $body['purchase_token'] ?? null;
        $purchaseToken = is_string($rawPurchaseToken) ? $rawPurchaseToken : '';
        /** @var mixed $rawPlan */
        $rawPlan = $body['plan'] ?? null;
        $plan = is_string($rawPlan) ? $rawPlan : '';

        $store = Store::tryFrom($storeValue);

        if ($store === null) {
            return Response::json(['error' => 'Invalid store: expected "google" or "apple"'], 422);
        }

        if ($purchaseToken === '') {
            return Response::json(['error' => 'purchase_token is required'], 422);
        }

        if ($plan === '') {
            return Response::json(['error' => 'plan is required'], 422);
        }

        try {
            $subscription = $this->subscriptionService->verifyAndSave(
                $userId,
                $store,
                $purchaseToken,
                $plan,
            );

            return Response::json([
                'status' => 'ok',
                'subscription' => self::serialize($subscription),
            ], 201);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/subscriptions/status
     *
     * Returns the current subscription status for the authenticated user.
     */
    public function status(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $subscription = $this->subscriptionService->getStatus($userId);

        if ($subscription === null) {
            return Response::json([
                'status' => 'none',
                'has_access' => false,
            ]);
        }

        return Response::json([
            'status' => $subscription->status->value,
            'has_access' => $subscription->hasAccess(),
            'subscription' => self::serialize($subscription),
        ]);
    }

    /**
     * POST /api/v1/subscriptions/restore
     *
     * Restores a subscription from a previous purchase (app reinstall, device transfer).
     *
     * Expected JSON body:
     *   { "store": "google"|"apple", "purchase_token": "...", "plan": "..." }
     */
    public function restore(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawStore */
        $rawStore = $body['store'] ?? null;
        $storeValue = is_string($rawStore) ? $rawStore : '';
        /** @var mixed $rawPurchaseToken */
        $rawPurchaseToken = $body['purchase_token'] ?? null;
        $purchaseToken = is_string($rawPurchaseToken) ? $rawPurchaseToken : '';
        /** @var mixed $rawPlan */
        $rawPlan = $body['plan'] ?? null;
        $plan = is_string($rawPlan) ? $rawPlan : '';

        $store = Store::tryFrom($storeValue);

        if ($store === null) {
            return Response::json(['error' => 'Invalid store: expected "google" or "apple"'], 422);
        }

        if ($purchaseToken === '') {
            return Response::json(['error' => 'purchase_token is required'], 422);
        }

        if ($plan === '') {
            return Response::json(['error' => 'plan is required'], 422);
        }

        try {
            $subscription = $this->subscriptionService->restore(
                $userId,
                $store,
                $purchaseToken,
                $plan,
            );

            return Response::json([
                'status' => 'ok',
                'subscription' => self::serialize($subscription),
            ]);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function resolveUserId(ServerRequestInterface $request): ?string
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /**
     * Serialize a subscription for API responses.
     *
     * Omits sensitive fields (purchase_token_hash, raw_receipt_encrypted).
     *
     * @return array<string, mixed>
     */
    private static function serialize(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'store' => $subscription->store->value,
            'product_id' => $subscription->productId,
            'plan' => $subscription->plan,
            'status' => $subscription->status->value,
            'has_access' => $subscription->hasAccess(),
            'expires_at' => $subscription->expiresAt?->format('c'),
            'grace_period_until' => $subscription->gracePeriodUntil?->format('c'),
            'created_at' => $subscription->createdAt->format('c'),
            'updated_at' => $subscription->updatedAt->format('c'),
        ];
    }
}
