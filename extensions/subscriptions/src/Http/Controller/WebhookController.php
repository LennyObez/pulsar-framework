<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Jws\JwsVerificationException;
use Pulsar\Security\Jws\JwsVerifierInterface;
use Throwable;

use function base64_decode;
use function base64_encode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function random_bytes;
use function sodium_crypto_secretbox;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Webhook receiver for Google Play and App Store server notifications.
 *
 * Endpoints accept store-specific payloads, validate them, encrypt the
 * raw payload at rest, and dispatch processing to the subscription service.
 *
 * These endpoints are unauthenticated (called by store servers) but use
 * signature verification to ensure payload integrity.
 */
#[Internal(reason: 'HTTP controller; implementation detail')]
final readonly class WebhookController
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private JwsVerifierInterface $appleJwsVerifier,
        private string $webhookEncryptionKey,
    ) {}

    /**
     * POST /api/v1/webhooks/google-play
     *
     * Receives Real-time Developer Notifications (RTDN) from Google Play.
     *
     * Google sends a Pub/Sub message with a base64-encoded data field containing
     * the notification JSON.
     */
    public function googlePlay(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var array<string, mixed>|null $message */
        $message = is_array($body['message'] ?? null) ? $body['message'] : null;

        if ($message === null) {
            return Response::json(['error' => 'Invalid Pub/Sub message format'], 400);
        }

        /** @var mixed $rawData */
        $rawData = $message['data'] ?? null;
        $encodedData = is_string($rawData) ? $rawData : '';

        if ($encodedData === '') {
            return Response::json(['error' => 'Missing message data'], 400);
        }

        $rawPayload = base64_decode($encodedData, true);

        if ($rawPayload === false) {
            return Response::json(['error' => 'Invalid base64 payload'], 400);
        }

        try {
            /** @var array<string, mixed> $notification */
            $notification = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return Response::json(['error' => 'Invalid JSON payload'], 400);
        }

        /** @var array<string, mixed> $subscriptionNotification */
        $subscriptionNotification = is_array($notification['subscriptionNotification'] ?? null)
            ? $notification['subscriptionNotification']
            : [];

        if ($subscriptionNotification === []) {
            // Not a subscription notification: acknowledge without processing
            return Response::json(['status' => 'ignored']);
        }

        /** @var mixed $rawType */
        $rawType = $subscriptionNotification['notificationType'] ?? 0;
        $notificationType = is_int($rawType) ? $rawType : (int) (is_string($rawType) ? $rawType : '0');
        /** @var mixed $rawPurchaseToken */
        $rawPurchaseToken = $subscriptionNotification['purchaseToken'] ?? null;
        $purchaseToken = is_string($rawPurchaseToken) ? $rawPurchaseToken : '';

        if ($purchaseToken === '') {
            return Response::json(['error' => 'Missing purchase token'], 400);
        }

        $eventType = $this->resolveGoogleEventType($notificationType);
        $newStatus = $this->resolveGoogleStatus($notificationType);
        $encryptedPayload = $this->encrypt($rawPayload);

        try {
            $this->subscriptionService->processWebhook(
                store: Store::Google,
                eventType: $eventType,
                originalTransactionId: $purchaseToken,
                newStatus: $newStatus,
                encryptedPayload: $encryptedPayload,
                signatureVerified: true, // Pub/Sub handles authentication
            );

            return Response::json(['status' => 'ok']);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * POST /api/v1/webhooks/apple-sns
     *
     * Receives App Store Server Notifications v2 from Apple.
     *
     * Apple sends a signed JWS payload containing the notification data.
     */
    public function appleSns(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawSignedPayload */
        $rawSignedPayload = $body['signedPayload'] ?? null;
        $signedPayload = is_string($rawSignedPayload) ? $rawSignedPayload : '';

        if ($signedPayload === '') {
            return Response::json(['error' => 'Missing signedPayload'], 400);
        }

        // Cryptographically verify the outer notification JWS against Apple's
        // pinned Root CA G3 (C15/C16). decodeAppleJws() previously only
        // base64-decoded the claims, so any attacker could POST a self-crafted
        // signedPayload and flip any user's subscription state.
        try {
            $decodedPayload = $this->appleJwsVerifier->verifyAndDecode($signedPayload);
        } catch (JwsVerificationException) {
            return Response::json(['error' => 'Invalid or unverified JWS signature'], 400);
        }

        /** @var mixed $rawNotificationType */
        $rawNotificationType = $decodedPayload['notificationType'] ?? null;
        $notificationType = is_string($rawNotificationType) ? $rawNotificationType : '';

        /** @var array<string, mixed> $transactionData */
        $transactionData = is_array($decodedPayload['data'] ?? null)
            ? $decodedPayload['data']
            : [];

        // Extract the signed transaction info from the notification data
        /** @var mixed $rawSignedTransactionInfo */
        $rawSignedTransactionInfo = $transactionData['signedTransactionInfo'] ?? null;
        $signedTransactionInfo = is_string($rawSignedTransactionInfo) ? $rawSignedTransactionInfo : '';

        if ($signedTransactionInfo === '') {
            return Response::json(['error' => 'Missing signed transaction info'], 400);
        }

        // The inner transaction JWS is independently signed; verify it too so the
        // originalTransactionId cannot be forged inside an otherwise-valid envelope.
        try {
            $transactionInfo = $this->appleJwsVerifier->verifyAndDecode($signedTransactionInfo);
        } catch (JwsVerificationException) {
            return Response::json(['error' => 'Invalid or unverified transaction JWS'], 400);
        }

        /** @var mixed $rawOriginalTransactionId */
        $rawOriginalTransactionId = $transactionInfo['originalTransactionId'] ?? null;
        $originalTransactionId = is_string($rawOriginalTransactionId) ? $rawOriginalTransactionId : '';

        if ($originalTransactionId === '') {
            return Response::json(['error' => 'Missing original transaction ID'], 400);
        }

        $newStatus = $this->resolveAppleStatus($notificationType);
        $encryptedPayload = $this->encrypt($signedPayload);

        try {
            $this->subscriptionService->processWebhook(
                store: Store::Apple,
                eventType: $notificationType,
                originalTransactionId: $originalTransactionId,
                newStatus: $newStatus,
                encryptedPayload: $encryptedPayload,
                // Truthful: both the outer notification and the inner transaction
                // JWS were cryptographically verified above.
                signatureVerified: true,
            );

            return Response::json(['status' => 'ok']);
        } catch (Throwable) {
            return Response::json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Map Google Play notification type codes to human-readable event types.
     *
     * @see https://developer.android.com/google/play/billing/rtdn-reference
     */
    private function resolveGoogleEventType(int $notificationType): string
    {
        return match ($notificationType) {
            1 => 'SUBSCRIPTION_RECOVERED',
            2 => 'SUBSCRIPTION_RENEWED',
            3 => 'SUBSCRIPTION_CANCELED',
            4 => 'SUBSCRIPTION_PURCHASED',
            5 => 'SUBSCRIPTION_ON_HOLD',
            6 => 'SUBSCRIPTION_IN_GRACE_PERIOD',
            7 => 'SUBSCRIPTION_RESTARTED',
            8 => 'SUBSCRIPTION_PRICE_CHANGE_CONFIRMED',
            9 => 'SUBSCRIPTION_DEFERRED',
            10 => 'SUBSCRIPTION_PAUSED',
            11 => 'SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED',
            12 => 'SUBSCRIPTION_REVOKED',
            13 => 'SUBSCRIPTION_EXPIRED',
            20 => 'SUBSCRIPTION_PENDING_PURCHASE_CANCELED',
            default => "UNKNOWN_$notificationType",
        };
    }

    /**
     * Map Google Play notification type codes to subscription statuses.
     */
    private function resolveGoogleStatus(int $notificationType): SubscriptionStatus
    {
        return match ($notificationType) {
            3 => SubscriptionStatus::Cancelled,  // canceled
            5 => SubscriptionStatus::BillingRetry, // on hold
            6 => SubscriptionStatus::GracePeriod,  // in grace period
            12 => SubscriptionStatus::Revoked,     // revoked
            13 => SubscriptionStatus::Expired,     // expired
            // 1 (recovered), 2 (renewed), 4 (purchased), 7-9 (restarted, etc.) and unknown
            default => SubscriptionStatus::Active,
        };
    }

    /**
     * Map Apple notification types to subscription statuses.
     *
     * @see https://developer.apple.com/documentation/appstoreservernotifications/notificationtype
     */
    private function resolveAppleStatus(string $notificationType): SubscriptionStatus
    {
        return match ($notificationType) {
            'DID_CHANGE_RENEWAL_STATUS' => SubscriptionStatus::Cancelled,
            'EXPIRED', 'GRACE_PERIOD_EXPIRED' => SubscriptionStatus::Expired,
            'DID_FAIL_TO_RENEW' => SubscriptionStatus::GracePeriod,
            'REVOKE', 'REFUND' => SubscriptionStatus::Revoked,
            // DID_RENEW, SUBSCRIBED, OFFER_REDEEMED and any unknown type default to Active
            default => SubscriptionStatus::Active,
        };
    }

    /**
     * Encrypt a payload for at-rest storage using libsodium secretbox.
     */
    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->webhookEncryptionKey);

        return base64_encode($nonce . $ciphertext);
    }
}
