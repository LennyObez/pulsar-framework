<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PayPalConfig;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function sprintf;
use function strtoupper;

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
        $gatewayId = is_string($resource['id'] ?? null) ? $resource['id'] : '';

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
        $billingAgreementId = is_string($resource['billing_agreement_id'] ?? null)
            ? $resource['billing_agreement_id']
            : '';

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
     * Verify PayPal webhook transmission signature.
     *
     * PayPal signs webhooks with a transmission signature computed over:
     * transmissionId|transmissionTime|webhookId|crc32(rawBody)
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

        // Validate cert URL is from PayPal's domain (prevent SSRF)
        $parsedUrl = parse_url($certUrl);
        $host = $parsedUrl['host'] ?? '';
        $scheme = $parsedUrl['scheme'] ?? '';

        if ($scheme !== 'https' || !$this->isPayPalCertHost($host)) {
            return false;
        }

        // Build the expected signature input per PayPal's spec:
        // transmissionId|transmissionTime|webhookId|crc32(rawBody)
        $crc = crc32($rawBody);
        $expectedSignatureInput = sprintf(
            '%s|%s|%s|%u',
            $transmissionId,
            $transmissionTime,
            $this->config->webhookId,
            $crc,
        );

        // Compute HMAC-SHA256 using webhookId as the key for local verification
        $expectedSignature = hash_hmac('sha256', $expectedSignatureInput, $this->config->webhookId);

        return hash_equals($expectedSignature, $transmissionSig);
    }

    /**
     * Check if the host is a valid PayPal certificate host.
     */
    private function isPayPalCertHost(string $host): bool
    {
        $allowedSuffixes = [
            '.paypal.com',
            '.symantec.com',
            '.verisign.com',
            '.paypal.com.',
        ];

        $host = strtoupper($host);

        foreach ($allowedSuffixes as $suffix) {
            if (str_ends_with($host, strtoupper($suffix))) {
                return true;
            }
        }

        return false;
    }
}
