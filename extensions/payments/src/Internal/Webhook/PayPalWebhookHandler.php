<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PayPalConfig;
use Pulsar\Extension\Payments\Contracts\PayPalCertificateProviderInterface;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

use function base64_decode;
use function crc32;
use function is_array;
use function is_string;
use function openssl_pkey_get_public;
use function openssl_verify;
use function sprintf;

use const OPENSSL_ALGO_SHA256;

/**
 * Handles PayPal webhook events.
 *
 * Processes billing subscription lifecycle events from PayPal.
 * Verifies webhook authenticity using PayPal transmission signature headers.
 */
#[Internal]
final readonly class PayPalWebhookHandler
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private PayPalConfig $config,
        private LoggerInterface $logger,
        private PayPalCertificateProviderInterface $certificateProvider,
    ) {}

    /**
     * Verify and process a PayPal webhook event.
     *
     * @param array<string, mixed> $event Decoded webhook payload
     * @param string $rawBody Raw JSON body for signature verification
     * @param array<string, string> $headers PayPal transmission headers
     *
     * @return array{verified: bool, event_type: string, processed: bool}
     */
    public function handle(array $event, string $rawBody = '', array $headers = []): array
    {
        if (!$this->verifySignature($rawBody, $headers)) {
            $this->logger->warning('PayPal webhook signature verification failed');

            return ['verified' => false, 'event_type' => '', 'processed' => false];
        }
        /** @var string $eventType */
        $eventType = is_string($event['event_type'] ?? null) ? $event['event_type'] : '';

        /** @var array<string, mixed> $resource */
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];

        $processed = match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->handleStatusChange($resource, SubscriptionStatus::Active),
            'BILLING.SUBSCRIPTION.CANCELLED' => $this->handleStatusChange($resource, SubscriptionStatus::Cancelled),
            'BILLING.SUBSCRIPTION.EXPIRED' => $this->handleStatusChange($resource, SubscriptionStatus::Expired),
            'BILLING.SUBSCRIPTION.SUSPENDED' => $this->handleStatusChange($resource, SubscriptionStatus::Paused),
            'PAYMENT.SALE.COMPLETED' => $this->handlePaymentCompleted($resource),
            default => false,
        };

        return ['verified' => true, 'event_type' => $eventType, 'processed' => $processed];
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function handleStatusChange(array $resource, SubscriptionStatus $newStatus): bool
    {
        /** @var mixed $rawGatewayId */
        $rawGatewayId = $resource['id'] ?? null;
        $gatewayId = is_string($rawGatewayId) ? $rawGatewayId : '';

        if ($gatewayId === '') {
            return false;
        }

        $subscription = $this->subscriptionRepository->findByGatewayId($gatewayId);

        if ($subscription === null) {
            $this->logger->warning('PayPal webhook: subscription not found', ['gateway_id' => $gatewayId]);

            return false;
        }

        $updated = $subscription->withStatus($newStatus);
        $this->subscriptionRepository->save($updated);

        $this->logger->info('PayPal subscription status updated', [
            'subscription_id' => $subscription->id,
            'new_status' => $newStatus->value,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function handlePaymentCompleted(array $resource): bool
    {
        /** @var mixed $rawBillingAgreementId */
        $rawBillingAgreementId = $resource['billing_agreement_id'] ?? null;
        $billingAgreementId = is_string($rawBillingAgreementId) ? $rawBillingAgreementId : '';

        if ($billingAgreementId === '') {
            return false;
        }

        $subscription = $this->subscriptionRepository->findByGatewayId($billingAgreementId);

        if ($subscription === null) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::PastDue) {
            $active = $subscription->withStatus(SubscriptionStatus::Active);
            $this->subscriptionRepository->save($active);
        }

        return true;
    }

    /**
     * Verify a PayPal webhook transmission signature.
     *
     * PayPal signs webhooks with RSA-SHA256. The signature in
     * PAYPAL-TRANSMISSION-SIG (base64) must be verified with openssl_verify
     * against the PUBLIC KEY of the signing certificate served at
     * PAYPAL-CERT-URL, over the message
     * transmissionId|transmissionTime|webhookId|crc32(rawBody).
     *
     * The previous implementation computed an HMAC keyed by the webhookId and
     * compared it to the signature. The webhookId is a PUBLIC identifier (shown
     * in the dashboard / returned by the API), so any party that knew it could
     * forge a passing signature and inject arbitrary subscription/payment
     * events (super-audit C14).
     *
     * @param string $rawBody Raw JSON body
     * @param array<string, string> $headers PayPal transmission headers
     *
     * @see https://developer.paypal.com/api/rest/webhooks/#link-eventnotifications
     */
    private function verifySignature(string $rawBody, array $headers): bool
    {
        if ($this->config->webhookId === '') {
            return false;
        }

        $transmissionId = $headers['PAYPAL-TRANSMISSION-ID'] ?? '';
        $transmissionTime = $headers['PAYPAL-TRANSMISSION-TIME'] ?? '';
        $transmissionSig = $headers['PAYPAL-TRANSMISSION-SIG'] ?? '';
        $certUrl = $headers['PAYPAL-CERT-URL'] ?? '';

        if ($transmissionId === '' || $transmissionTime === '' || $transmissionSig === '' || $certUrl === '') {
            return false;
        }

        // Resolve the signing certificate's public key. The provider allow-lists
        // the (attacker-supplied) cert URL to PayPal hosts and returns null on
        // any failure — treat that as "reject".
        $publicKeyPem = $this->certificateProvider->publicKeyPemFor($certUrl);
        if ($publicKeyPem === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        $message = sprintf(
            '%s|%s|%s|%u',
            $transmissionId,
            $transmissionTime,
            $this->config->webhookId,
            crc32($rawBody),
        );

        $signature = base64_decode($transmissionSig, true);
        if ($signature === false) {
            return false;
        }

        return openssl_verify($message, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
