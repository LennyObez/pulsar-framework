<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Billing;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

use function count;

/**
 * Dunning (failed payment retry) manager.
 *
 * When a subscription renewal payment fails, this manager:
 * 1. Transitions the subscription to PastDue
 * 2. Schedules retry attempts with exponential backoff
 * 3. After max retries, transitions to Expired
 *
 * Retry schedule (default 4 retries):
 *   Day 1  - first retry
 *   Day 3  - second retry
 *   Day 7  - third retry
 *   Day 14 - final retry, then expire
 */
#[Internal]
final readonly class DunningManager
{
    /**
     * Retry intervals in days (exponential backoff).
     */
    private const array RETRY_INTERVALS = [1, 3, 7, 14];

    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private LoggerInterface $logger,
        private PaymentsConfig $config,
    ) {}

    /**
     * Transition a subscription to the dunning (past due) state.
     */
    public function enterDunning(Subscription $subscription): Subscription
    {
        $pastDue = $subscription->withStatus(SubscriptionStatus::PastDue);
        $this->subscriptionRepository->save($pastDue);

        $this->logger->warning('Subscription entered dunning', [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customerId,
            'max_retries' => $this->config->dunningMaxRetries,
        ]);

        return $pastDue;
    }

    /**
     * Check if the subscription has exhausted all retry attempts.
     */
    public function shouldExpire(Subscription $subscription, int $retryCount): bool
    {
        return $retryCount >= $this->config->dunningMaxRetries;
    }

    /**
     * Expire a subscription after exhausting dunning retries.
     */
    public function expire(Subscription $subscription): Subscription
    {
        $expired = $subscription->withStatus(SubscriptionStatus::Expired);
        $this->subscriptionRepository->save($expired);

        $this->logger->warning('Subscription expired after dunning', [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customerId,
        ]);

        return $expired;
    }

    /**
     * Get the number of days until the next retry.
     */
    public function nextRetryIntervalDays(int $retryCount): int
    {
        $index = min($retryCount, count(self::RETRY_INTERVALS) - 1);

        return self::RETRY_INTERVALS[$index];
    }
}
