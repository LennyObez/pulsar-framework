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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private const array RETRY_INTERVALS = [1, 3, 7, 14];

    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private LoggerInterface $logger,
        private PaymentsConfig $config,
    ) {}

    /**
     * Transition a subscription to the dunning (past due) state.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
     * Check if a subscription has exhausted all retry attempts.
     *
     * The decision is currently driven entirely by the configured retry budget;
     * future per-subscription overrides can re-introduce the subscription
     * parameter.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function shouldExpire(int $retryCount): bool
    {
        return $retryCount >= $this->config->dunningMaxRetries;
    }

    /**
     * Expire a subscription after exhausting dunning retries.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function nextRetryIntervalDays(int $retryCount): int
    {
        $index = max(0, min($retryCount, count(self::RETRY_INTERVALS) - 1));

        return self::RETRY_INTERVALS[$index];
    }
}
