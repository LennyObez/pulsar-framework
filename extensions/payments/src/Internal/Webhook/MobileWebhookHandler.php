<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Webhook;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Internal\Mobile\JwsVerifier;
use Pulsar\Extension\Payments\Internal\Persistence\DbSubscriptionRepository;
use Throwable;

use function base64_decode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Handles mobile store webhook events (Google Play RTDN, Apple Server Notifications v2).
 *
 * Merged from the former subscriptions extension.
 */
#[Internal]
final readonly class MobileWebhookHandler
{
    public function __construct(
        private DbSubscriptionRepository $subscriptionRepository,
        private string $encryptionKey,
        private LoggerInterface $logger,
    ) {}

    /**
     * Process a Google Play RTDN webhook.
     *
     * @param array<string, mixed> $body Parsed request body
     *
     * @return array{status: string, event_type: string}
     */
    public function handleGooglePlay(array $body): array
    {
        if ($this->encryptionKey === '') {
            $this->logger->warning('Mobile webhook encryption key is not configured');
        }

        /** @var array<string, mixed>|null $message */
        $message = is_array($body['message'] ?? null) ? $body['message'] : null;

        if ($message === null) {
            return ['status' => 'invalid_format', 'event_type' => ''];
        }

        $rawData = $message['data'] ?? null;
        $encodedData = is_string($rawData) ? $rawData : '';

        if ($encodedData === '') {
            return ['status' => 'missing_data', 'event_type' => ''];
        }

        $rawPayload = base64_decode($encodedData, true);

        if ($rawPayload === false) {
            return ['status' => 'invalid_base64', 'event_type' => ''];
        }

        try {
            /** @var array<string, mixed> $notification */
            $notification = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['status' => 'invalid_json', 'event_type' => ''];
        }

        /** @var array<string, mixed> $subscriptionNotification */
        $subscriptionNotification = is_array($notification['subscriptionNotification'] ?? null)
            ? $notification['subscriptionNotification']
            : [];

        if ($subscriptionNotification === []) {
            return ['status' => 'ignored', 'event_type' => ''];
        }

        $rawType = $subscriptionNotification['notificationType'] ?? 0;
        $notificationType = is_int($rawType) ? $rawType : (int) (is_string($rawType) ? $rawType : '0');
        $rawPurchaseToken = $subscriptionNotification['purchaseToken'] ?? null;
        $purchaseToken = is_string($rawPurchaseToken) ? $rawPurchaseToken : '';

        if ($purchaseToken === '') {
            return ['status' => 'missing_token', 'event_type' => ''];
        }

        $eventType = self::resolveGoogleEventType($notificationType);
        $newStatus = self::resolveGoogleStatus($notificationType);

        $subscription = $this->subscriptionRepository->findByOriginalTransactionId($purchaseToken);

        if ($subscription !== null) {
            $updated = $subscription->withStatus($newStatus);
            $this->subscriptionRepository->save($updated);

            $this->logger->info('Google Play webhook processed', [
                'subscription_id' => $subscription->id,
                'event_type' => $eventType,
                'new_status' => $newStatus->value,
            ]);
        } else {
            $this->logger->warning('Google Play webhook: subscription not found', [
                'event_type' => $eventType,
            ]);
        }

        return ['status' => 'ok', 'event_type' => $eventType];
    }

    /**
     * Process an Apple App Store Server Notification v2.
     *
     * @param array<string, mixed> $body Parsed request body
     *
     * @return array{status: string, event_type: string}
     */
    public function handleAppleSns(array $body): array
    {
        $rawSignedPayload = $body['signedPayload'] ?? null;
        $signedPayload = is_string($rawSignedPayload) ? $rawSignedPayload : '';

        if ($signedPayload === '') {
            return ['status' => 'missing_payload', 'event_type' => ''];
        }

        $decoded = self::decodeJws($signedPayload);

        if ($decoded === null) {
            return ['status' => 'invalid_jws', 'event_type' => ''];
        }

        /** @var mixed $rawNotificationType */
        $rawNotificationType = $decoded['notificationType'] ?? null;
        $notificationType = is_string($rawNotificationType) ? $rawNotificationType : '';

        /** @var array<string, mixed> $transactionData */
        $transactionData = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        /** @var mixed $rawSignedTransactionInfo */
        $rawSignedTransactionInfo = $transactionData['signedTransactionInfo'] ?? null;
        $signedTransactionInfo = is_string($rawSignedTransactionInfo) ? $rawSignedTransactionInfo : '';

        $transactionInfo = $signedTransactionInfo !== '' ? self::decodeJws($signedTransactionInfo) : null;

        /** @var mixed $rawOriginalTransactionId */
        $rawOriginalTransactionId = $transactionInfo['originalTransactionId'] ?? null;
        $originalTransactionId = $transactionInfo !== null && is_string($rawOriginalTransactionId)
            ? $rawOriginalTransactionId
            : '';

        if ($originalTransactionId === '') {
            return ['status' => 'missing_transaction_id', 'event_type' => $notificationType];
        }

        $newStatus = self::resolveAppleStatus($notificationType);

        $subscription = $this->subscriptionRepository->findByOriginalTransactionId($originalTransactionId);

        if ($subscription !== null) {
            $updated = $subscription->withStatus($newStatus);
            $this->subscriptionRepository->save($updated);

            $this->logger->info('Apple webhook processed', [
                'subscription_id' => $subscription->id,
                'event_type' => $notificationType,
                'new_status' => $newStatus->value,
            ]);
        } else {
            $this->logger->warning('Apple webhook: subscription not found', [
                'event_type' => $notificationType,
                'original_transaction_id' => $originalTransactionId,
            ]);
        }

        return ['status' => 'ok', 'event_type' => $notificationType];
    }

    private static function resolveGoogleEventType(int $notificationType): string
    {
        return match ($notificationType) {
            1 => 'SUBSCRIPTION_RECOVERED',
            2 => 'SUBSCRIPTION_RENEWED',
            3 => 'SUBSCRIPTION_CANCELED',
            4 => 'SUBSCRIPTION_PURCHASED',
            5 => 'SUBSCRIPTION_ON_HOLD',
            6 => 'SUBSCRIPTION_IN_GRACE_PERIOD',
            7 => 'SUBSCRIPTION_RESTARTED',
            12 => 'SUBSCRIPTION_REVOKED',
            13 => 'SUBSCRIPTION_EXPIRED',
            default => "UNKNOWN_$notificationType",
        };
    }

    private static function resolveGoogleStatus(int $notificationType): SubscriptionStatus
    {
        return match ($notificationType) {
            3 => SubscriptionStatus::Cancelled,
            5 => SubscriptionStatus::PastDue,
            6 => SubscriptionStatus::GracePeriod,
            12 => SubscriptionStatus::Revoked,
            13 => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Active,
        };
    }

    private static function resolveAppleStatus(string $notificationType): SubscriptionStatus
    {
        return match ($notificationType) {
            'DID_CHANGE_RENEWAL_STATUS' => SubscriptionStatus::Cancelled,
            'EXPIRED', 'GRACE_PERIOD_EXPIRED' => SubscriptionStatus::Expired,
            'DID_FAIL_TO_RENEW' => SubscriptionStatus::GracePeriod,
            'REVOKE', 'REFUND' => SubscriptionStatus::Revoked,
            default => SubscriptionStatus::Active,
        };
    }

    /**
     * Verify and decode a JWS token using the x5c certificate chain.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJws(string $jws): ?array
    {
        try {
            return JwsVerifier::verifyAndDecode($jws);
        } catch (PaymentException) {
            return null;
        }
    }
}
