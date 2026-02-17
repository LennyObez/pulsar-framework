<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PayconiqConfig;

use function hash_equals;
use function hash_hmac;
use function is_string;

/**
 * Handles Payconiq webhook callbacks.
 *
 * Payconiq sends webhook notifications when a payment status changes:
 * - PENDING: Payment created, awaiting customer authorization
 * - IDENTIFIED: Customer scanned the QR code
 * - AUTHORIZED: Customer authorized the payment
 * - SUCCEEDED: Payment completed successfully
 * - FAILED: Payment failed
 * - CANCELLED: Payment cancelled by customer or merchant
 * - EXPIRED: Payment timed out
 */
#[Internal]
final readonly class PayconiqWebhookHandler
{
    public function __construct(
        private PayconiqConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Verify and process a Payconiq webhook event.
     *
     * @return array{verified: bool, payment_id: string, status: string, processed: bool}
     */
    public function handle(string $payload, string $signatureHeader): array
    {
        if (!$this->verifySignature($payload, $signatureHeader)) {
            $this->logger->warning('Payconiq webhook signature verification failed');

            return ['verified' => false, 'payment_id' => '', 'status' => '', 'processed' => false];
        }

        /** @var array<string, mixed> $event */
        $event = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);

        $paymentId = is_string($event['paymentId'] ?? null) ? $event['paymentId'] : '';
        $status = is_string($event['status'] ?? null) ? $event['status'] : '';

        if ($paymentId === '' || $status === '') {
            return ['verified' => true, 'payment_id' => $paymentId, 'status' => $status, 'processed' => false];
        }

        $processed = $this->processStatusChange($paymentId, $status);

        return ['verified' => true, 'payment_id' => $paymentId, 'status' => $status, 'processed' => $processed];
    }

    private function processStatusChange(string $paymentId, string $status): bool
    {
        $this->logger->info('Payconiq payment status changed', [
            'payment_id' => $paymentId,
            'status' => $status,
        ]);

        return match ($status) {
            'SUCCEEDED', 'AUTHORIZED', 'IDENTIFIED', 'CANCELLED', 'FAILED', 'EXPIRED' => true,
            default => false,
        };
    }

    private function verifySignature(string $payload, string $signatureHeader): bool
    {
        if ($this->config->webhookSecret === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->config->webhookSecret);

        return hash_equals($expectedSignature, $signatureHeader);
    }
}
