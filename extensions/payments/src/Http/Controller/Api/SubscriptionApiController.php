<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\MobileVerifierInterface;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Internal\Persistence\DbSubscriptionRepository;
use Pulsar\Extension\Payments\Internal\Security\PaymentTokenizer;
use Pulsar\Http\Message\Response;
use Throwable;

use function is_string;

/**
 * Subscription API controller.
 *
 * Provides endpoints for mobile app subscription verification,
 * status queries, and subscription restoration.
 */
#[Internal]
final readonly class SubscriptionApiController
{
    public function __construct(
        private DbSubscriptionRepository $subscriptionRepository,
        private MobileVerifierInterface $mobileVerifier,
    ) {}

    /**
     * POST /api/v1/subscriptions/verify
     *
     * Verify a mobile in-app purchase token.
     */
    public function verify(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $storeValue = is_string($body['store'] ?? null) ? $body['store'] : '';
        $purchaseToken = is_string($body['purchase_token'] ?? null) ? $body['purchase_token'] : '';
        $planId = is_string($body['plan_id'] ?? null) ? $body['plan_id'] : '';

        $store = MobileStore::tryFrom($storeValue);

        if ($store === null) {
            return Response::json(['error' => 'Invalid store'], 422);
        }

        if ($purchaseToken === '' || $planId === '') {
            return Response::json(['error' => 'purchase_token and plan_id are required'], 422);
        }

        try {
            $result = $this->mobileVerifier->verify($store, $purchaseToken);

            if (!$result->isValid) {
                return Response::json(['error' => 'Purchase verification failed'], 422);
            }

            $tokenHash = PaymentTokenizer::hashPurchaseToken($purchaseToken);

            // Check for existing subscription
            $existing = $this->subscriptionRepository->findByPurchaseTokenHash($tokenHash);

            if ($existing !== null) {
                $updated = $existing->withStatus(
                    \Pulsar\Extension\Payments\Domain\SubscriptionStatus::Active,
                    currentPeriodEnd: $result->expiresAt,
                    gracePeriodUntil: $result->gracePeriodUntil,
                );

                $this->subscriptionRepository->save($updated);

                return Response::json([
                    'status' => 'ok',
                    'subscription' => self::serialize($updated),
                ]);
            }

            $subscription = Subscription::createMobile(
                customerId: $userId,
                planId: $planId,
                store: $store,
                purchaseTokenHash: $tokenHash,
                originalTransactionId: $purchaseToken,
                expiresAt: $result->expiresAt,
            );

            $this->subscriptionRepository->save($subscription);

            return Response::json([
                'status' => 'ok',
                'subscription' => self::serialize($subscription),
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/subscriptions/status
     *
     * Get subscription status for the authenticated user.
     */
    public function status(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $subscriptions = $this->subscriptionRepository->findByCustomer($userId);

        if ($subscriptions === []) {
            return Response::json(['status' => 'none', 'has_access' => false]);
        }

        $active = array_filter($subscriptions, static fn($s) => $s->hasAccess());

        return Response::json([
            'status' => $active !== [] ? 'active' : 'inactive',
            'has_access' => $active !== [],
            'subscriptions' => array_map(self::serialize(...), $subscriptions),
        ]);
    }

    private function resolveUserId(ServerRequestInterface $request): ?string
    {
        $userId = $request->getAttribute('user_id');

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'plan_id' => $subscription->planId,
            'status' => $subscription->status->value,
            'has_access' => $subscription->hasAccess(),
            'billing_cycle' => $subscription->billingCycle->value,
            'current_period_end' => $subscription->currentPeriodEnd?->format('c'),
            'trial_end' => $subscription->trialEnd?->format('c'),
            'mobile_store' => $subscription->mobileStore?->value,
            'created_at' => $subscription->createdAt->format('c'),
        ];
    }
}
