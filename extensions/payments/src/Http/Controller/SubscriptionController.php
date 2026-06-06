<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\SubscriptionManagerInterface;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Http\Message\Response;
use Throwable;

use function is_int;
use function is_numeric;
use function is_string;
use function strtoupper;

/**
 * REST API controller for subscription management.
 */
#[Internal]
final readonly class SubscriptionController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private SubscriptionManagerInterface $subscriptionManager,
    ) {}

    /**
     * POST /payments/subscriptions
     *
     * Create a new subscription.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawPlanId */
        $rawPlanId = $body['plan_id'] ?? null;
        $planId = is_string($rawPlanId) ? $rawPlanId : '';
        /** @var mixed $rawCycle */
        $rawCycle = $body['billing_cycle'] ?? null;
        $cycleValue = is_string($rawCycle) ? $rawCycle : '';
        /** @var mixed $amountRaw */
        $amountRaw = $body['amount'] ?? null;
        $amount = is_int($amountRaw) ? $amountRaw : (is_string($amountRaw) && is_numeric($amountRaw) ? (int) $amountRaw : 0);
        /** @var mixed $rawCurrency */
        $rawCurrency = $body['currency'] ?? null;
        $currencyCode = is_string($rawCurrency) ? strtoupper($rawCurrency) : 'USD';
        /** @var mixed $rawGateway */
        $rawGateway = $body['gateway'] ?? null;
        $gateway = is_string($rawGateway) ? $rawGateway : '';
        /** @var mixed $trialRaw */
        $trialRaw = $body['trial_days'] ?? null;
        $trialDays = is_int($trialRaw) ? $trialRaw : (is_string($trialRaw) && is_numeric($trialRaw) ? (int) $trialRaw : null);

        if ($planId === '') {
            return Response::json(['error' => 'plan_id is required'], 422);
        }

        $cycle = BillingCycle::tryFrom($cycleValue);

        if ($cycle === null) {
            return Response::json(['error' => 'Invalid billing_cycle'], 422);
        }

        $currency = Currency::tryFrom($currencyCode);

        if ($currency === null) {
            return Response::json(['error' => "Unsupported currency: $currencyCode"], 422);
        }

        try {
            $subscription = $this->subscriptionManager->create(
                customerId: $userId,
                planId: $planId,
                billingCycle: $cycle,
                amount: Money::of($amount, $currency),
                gateway: $gateway,
                trialDays: $trialDays,
            );

            return Response::json([
                'status' => 'ok',
                'subscription' => self::serialize($subscription),
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /payments/subscriptions
     *
     * List subscriptions for the authenticated user.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function list(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $subscriptions = $this->subscriptionManager->getByCustomer($userId);

        return Response::json([
            'subscriptions' => array_map(self::serialize(...), $subscriptions),
        ]);
    }

    /**
     * POST /payments/subscriptions/{id}/cancel
     *
     * Cancel a subscription.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function cancel(ServerRequestInterface $request): Response
    {
        /** @var mixed $rawId */
        $rawId = $request->getAttribute('id');
        $subscriptionId = is_string($rawId) ? $rawId : '';

        if ($subscriptionId === '') {
            return Response::json(['error' => 'Missing subscription ID'], 400);
        }

        try {
            $subscription = $this->subscriptionManager->cancel($subscriptionId);

            return Response::json([
                'status' => 'ok',
                'subscription' => self::serialize($subscription),
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /payments/subscriptions/{id}/pause
     *
     * Pause a subscription.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function pause(ServerRequestInterface $request): Response
    {
        /** @var mixed $rawId */
        $rawId = $request->getAttribute('id');
        $subscriptionId = is_string($rawId) ? $rawId : '';

        if ($subscriptionId === '') {
            return Response::json(['error' => 'Missing subscription ID'], 400);
        }

        try {
            $subscription = $this->subscriptionManager->pause($subscriptionId);

            return Response::json([
                'status' => 'ok',
                'subscription' => self::serialize($subscription),
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /payments/subscriptions/{id}/resume
     *
     * Resume a paused subscription.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function resume(ServerRequestInterface $request): Response
    {
        /** @var mixed $rawId */
        $rawId = $request->getAttribute('id');
        $subscriptionId = is_string($rawId) ? $rawId : '';

        if ($subscriptionId === '') {
            return Response::json(['error' => 'Missing subscription ID'], 400);
        }

        try {
            $subscription = $this->subscriptionManager->resume($subscriptionId);

            return Response::json([
                'status' => 'ok',
                'subscription' => self::serialize($subscription),
            ]);
        } catch (Throwable $e) {
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
     * @return array<string, mixed>
     */
    private static function serialize(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'plan_id' => $subscription->planId,
            'status' => $subscription->status->value,
            'billing_cycle' => $subscription->billingCycle->value,
            'amount' => $subscription->amount->amount,
            'currency' => $subscription->amount->currency->value,
            'has_access' => $subscription->hasAccess(),
            'current_period_end' => $subscription->currentPeriodEnd?->format('c'),
            'trial_end' => $subscription->trialEnd?->format('c'),
            'cancelled_at' => $subscription->cancelledAt?->format('c'),
            'created_at' => $subscription->createdAt->format('c'),
        ];
    }
}
