<?php

declare(strict_types=1);

namespace App\Job;

use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;

/**
 * Background job that processes a payment.
 *
 * Demonstrates the Pulsar queue job pattern:
 * - Implement QueueableInterface
 * - Define handle() with your job logic
 * - Configure queue(), maxAttempts(), and timeout()
 * - Dispatch via QueueManager::dispatch($job)
 *
 * Jobs are serialized, pushed to a queue driver (Redis, AMQP, SQS,
 * database, or in-memory), and executed by the queue worker:
 *
 *   php bin/pulsar queue:work --queue=payments
 */
final class ProcessPaymentJob implements QueueableInterface
{
    public function __construct(
        private readonly int $orderId,
        private readonly float $amount,
        private readonly string $currency,
        private readonly string $paymentMethod,
    ) {}

    /**
     * Execute the payment processing logic.
     *
     * The JobContext provides access to:
     * - $context->attempt()   - current attempt number
     * - $context->jobId()     - unique job identifier
     * - $context->logger()    - PSR-3 logger
     */
    public function handle(JobContext $context): void
    {
        $context->logger()?->info('Processing payment', [
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'attempt' => $context->attempt(),
        ]);

        // Simulate payment processing
        // In a real app, call a payment gateway:
        //   $result = $this->gateway->charge($this->amount, $this->currency, $this->paymentMethod);
        //   if (!$result->success) { throw new PaymentFailedException($result->error); }

        echo sprintf(
            "[ProcessPaymentJob] Order #%d: charged %.2f %s via %s (attempt %d)\n",
            $this->orderId,
            $this->amount,
            $this->currency,
            $this->paymentMethod,
            $context->attempt(),
        );
    }

    /**
     * The queue this job should be dispatched to.
     */
    public function queue(): string
    {
        return 'payments';
    }

    /**
     * Maximum attempts before the job is marked as failed.
     *
     * Failed jobs are moved to the dead-letter queue for inspection.
     */
    public function maxAttempts(): int
    {
        return 3;
    }

    /**
     * Maximum execution time in seconds.
     *
     * Payment processing should complete within 30 seconds.
     */
    public function timeout(): int
    {
        return 30;
    }
}
