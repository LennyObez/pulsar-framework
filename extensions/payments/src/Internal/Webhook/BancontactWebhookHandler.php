<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\StripeConfig;

use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function json_decode;
use function str_starts_with;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Handles Bancontact webhook events via Stripe.
 *
 * Bancontact payments are processed through Stripe's Payment Methods API.
 * Webhook events follow the Stripe signature verification scheme.
 *
 * Relevant events:
 * - payment_intent.succeeded: Payment completed
 * - payment_intent.payment_failed: Payment failed
 * - charge.refunded: Refund completed
 */
#[Internal]
final readonly class BancontactWebhookHandler
{
    public function __construct(
        private StripeConfig $stripeConfig,
        private LoggerInterface $logger,
    ) {}

    /**
     * Verify and process a Bancontact webhook event (routed via Stripe).
     *
     * @return array{verified: bool, event_type: string, processed: bool}
     */
    public function handle(string $payload, string $signatureHeader): array
    {
        if (!$this->verifyStripeSignature($payload, $signatureHeader)) {
            $this->logger->warning('Bancontact webhook signature verification failed');

            return ['verified' => false, 'event_type' => '', 'processed' => false];
        }

        /** @var array<string, mixed> $event */
        $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);

        /** @var string $eventType */
        $eventType = is_string($event['type'] ?? null) ? $event['type'] : '';

        /** @var array<string, mixed> $eventData */
        $eventData = is_array($event['data'] ?? null) ? $event['data'] : [];
        /** @var array<string, mixed> $object */
        $object = is_array($eventData['object'] ?? null) ? $eventData['object'] : [];

        // Only process Bancontact-related events
        $paymentMethodType = $this->extractPaymentMethodType($object);

        if ($paymentMethodType !== 'bancontact') {
            return ['verified' => true, 'event_type' => $eventType, 'processed' => false];
        }

        $processed = $this->processEvent($eventType, $object);

        return ['verified' => true, 'event_type' => $eventType, 'processed' => $processed];
    }

    /**
     * @param array<string, mixed> $object
     */
    private function processEvent(string $eventType, array $object): bool
    {
        /** @var mixed $rawIntentId */
        $rawIntentId = $object['id'] ?? null;
        $intentId = is_string($rawIntentId) ? $rawIntentId : '';

        if ($intentId === '') {
            return false;
        }

        return match ($eventType) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($intentId),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($intentId, $object),
            'charge.refunded' => $this->handleRefunded($intentId),
            default => false,
        };
    }

    private function handlePaymentSucceeded(string $intentId): bool
    {
        $this->logger->info('Bancontact payment succeeded', ['intent_id' => $intentId]);

        return true;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handlePaymentFailed(string $intentId, array $object): bool
    {
        /** @var mixed $rawLastError */
        $rawLastError = $object['last_payment_error'] ?? null;
        /** @var array<string, mixed> $lastError */
        $lastError = is_array($rawLastError) ? $rawLastError : [];
        /** @var mixed $rawMessage */
        $rawMessage = $lastError['message'] ?? null;
        $message = is_string($rawMessage) ? $rawMessage : 'Unknown error';

        $this->logger->warning('Bancontact payment failed', [
            'intent_id' => $intentId,
            'error' => $message,
        ]);

        return true;
    }

    private function handleRefunded(string $intentId): bool
    {
        $this->logger->info('Bancontact payment refunded', ['intent_id' => $intentId]);

        return true;
    }

    /**
     * Extract the payment method type from a Stripe event object.
     *
     * @param array<string, mixed> $object
     */
    private function extractPaymentMethodType(array $object): string
    {
        // Check payment_method_types array
        /** @var mixed $rawTypes */
        $rawTypes = $object['payment_method_types'] ?? null;
        /** @var list<mixed> $types */
        $types = is_array($rawTypes) ? $rawTypes : [];

        /** @var mixed $type */
        foreach ($types as $type) {
            if ($type === 'bancontact') {
                return 'bancontact';
            }
        }

        return '';
    }

    private function verifyStripeSignature(string $payload, string $signatureHeader): bool
    {
        if ($this->stripeConfig->webhookSecret === '') {
            return false;
        }

        $parts = explode(',', $signatureHeader);
        $timestamp = '';
        $signatures = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $signatures[] = substr($part, 3);
            }
        }

        if ($timestamp === '' || $signatures === []) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', "$timestamp.$payload", $this->stripeConfig->webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }
}
