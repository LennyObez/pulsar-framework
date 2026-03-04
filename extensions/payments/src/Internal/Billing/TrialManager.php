<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Billing;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;

/**
 * Manages trial periods for subscriptions.
 *
 * Enforces maximum trial duration from configuration,
 * prevents trial abuse (one trial per customer per plan),
 * and handles trial-to-paid transitions.
 */
#[Internal]
final readonly class TrialManager
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private LoggerInterface $logger,
        private PaymentsConfig $config,
    ) {}

    /**
     * Start a trial for a subscription.
     *
     * @throws PaymentException If the customer already had a trial for this plan
     */
    public function startTrial(
        string $customerId,
        string $planId,
        BillingCycle $billingCycle,
        Money $amount,
        string $gateway,
        int $trialDays,
    ): Subscription {
        if ($trialDays > $this->config->trialMaxDays) {
            throw PaymentException::invalid(
                "Trial duration ($trialDays days) exceeds maximum ({$this->config->trialMaxDays} days)",
            );
        }

        if ($trialDays <= 0) {
            throw PaymentException::invalid('Trial duration must be positive');
        }

        // Check for existing trial abuse
        $existing = $this->subscriptionRepository->findByCustomer($customerId);

        foreach ($existing as $sub) {
            if ($sub->planId === $planId && $sub->trialEnd !== null) {
                throw PaymentException::invalid(
                    'Customer already used a trial for this plan',
                );
            }
        }

        $trialEnd = new DateTimeImmutable()->modify("+$trialDays days");

        $subscription = Subscription::create(
            customerId: $customerId,
            planId: $planId,
            billingCycle: $billingCycle,
            amount: $amount,
            gateway: $gateway,
            trialEnd: $trialEnd,
        );

        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Trial started', [
            'subscription_id' => $subscription->id,
            'customer_id' => $customerId,
            'plan_id' => $planId,
            'trial_end' => $trialEnd->format('c'),
        ]);

        return $subscription;
    }

    /**
     * Convert a trial to a paid subscription.
     */
    public function convertToPaid(Subscription $subscription): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::Trialing) {
            throw PaymentException::invalidTransition(
                'Subscription',
                $subscription->status->value,
                SubscriptionStatus::Active->value,
            );
        }

        $converted = $subscription->withStatus(SubscriptionStatus::Active);
        $this->subscriptionRepository->save($converted);

        $this->logger->info('Trial converted to paid', [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customerId,
        ]);

        return $converted;
    }

    /**
     * Expire a trial that has ended without conversion.
     */
    public function expireTrial(Subscription $subscription): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::Trialing) {
            return $subscription;
        }

        $expired = $subscription->withStatus(SubscriptionStatus::Expired);
        $this->subscriptionRepository->save($expired);

        $this->logger->info('Trial expired', [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customerId,
        ]);

        return $expired;
    }
}
