<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Http\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Subscriptions\Http\Controller\WebhookController;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;
use RuntimeException;
use Stringable;

use function base64_encode;
use function json_decode;
use function json_encode;
use function sodium_crypto_secretbox_keygen;

use const JSON_THROW_ON_ERROR;

#[CoversClass(WebhookController::class)]
final class WebhookControllerTest extends TestCase
{
    private string $encryptionKey;

    protected function setUp(): void
    {
        $this->encryptionKey = sodium_crypto_secretbox_keygen();
    }

    // --- Google Play webhook tests ---

    #[Test]
    public function googlePlayReturns400ForMissingMessage(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/webhooks/google-play', parsedBody: []);
        $response = $controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Pub/Sub', $body['error']);
    }

    #[Test]
    public function googlePlayReturns400ForMissingMessageData(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['attributes' => []]],
        );
        $response = $controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('data', $body['error']);
    }

    #[Test]
    public function googlePlayReturns400ForInvalidBase64(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['data' => '!!!not-base64!!!']],
        );
        $response = $controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function googlePlayReturns400ForInvalidJson(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['data' => base64_encode('not json')]],
        );
        $response = $controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('JSON', $body['error']);
    }

    #[Test]
    public function googlePlayReturnsIgnoredForNonSubscriptionNotification(): void
    {
        $controller = $this->makeController();

        $payload = json_encode(['oneTimePurchaseNotification' => []], JSON_THROW_ON_ERROR);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['data' => base64_encode($payload)]],
        );

        $response = $controller->googlePlay($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ignored', $body['status']);
    }

    #[Test]
    public function googlePlayReturns400ForMissingPurchaseToken(): void
    {
        $controller = $this->makeController();

        $payload = json_encode([
            'subscriptionNotification' => [
                'notificationType' => 2,
                'purchaseToken' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['data' => base64_encode($payload)]],
        );

        $response = $controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('purchase token', $body['error']);
    }

    #[Test]
    public function googlePlayReturnsOkForValidSubscriptionNotification(): void
    {
        $service = $this->makeService();
        $controller = new WebhookController($service, $this->encryptionKey);

        $payload = json_encode([
            'subscriptionNotification' => [
                'notificationType' => 2,
                'purchaseToken' => 'purchase-token-123',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['data' => base64_encode($payload)]],
        );

        $response = $controller->googlePlay($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function googlePlayReturns500WhenProcessingFails(): void
    {
        // Create a service whose subRepo.findByOriginalTransactionId returns a subscription,
        // then cause an error. Since processWebhook calls webhookRepo.save first, and then
        // subRepo.findByOriginalTransactionId, we can make subRepo throw.
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willThrowException(new RuntimeException('DB error'));

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new WebhookController($service, $this->encryptionKey);

        $payload = json_encode([
            'subscriptionNotification' => [
                'notificationType' => 4,
                'purchaseToken' => 'token-xyz',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['data' => base64_encode($payload)]],
        );

        $response = $controller->googlePlay($request);

        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function googleEventTypeProvider(): iterable
    {
        yield 'recovered' => [1, 'SUBSCRIPTION_RECOVERED'];
        yield 'renewed' => [2, 'SUBSCRIPTION_RENEWED'];
        yield 'canceled' => [3, 'SUBSCRIPTION_CANCELED'];
        yield 'purchased' => [4, 'SUBSCRIPTION_PURCHASED'];
        yield 'on_hold' => [5, 'SUBSCRIPTION_ON_HOLD'];
        yield 'grace_period' => [6, 'SUBSCRIPTION_IN_GRACE_PERIOD'];
        yield 'restarted' => [7, 'SUBSCRIPTION_RESTARTED'];
        yield 'price_change' => [8, 'SUBSCRIPTION_PRICE_CHANGE_CONFIRMED'];
        yield 'deferred' => [9, 'SUBSCRIPTION_DEFERRED'];
        yield 'paused' => [10, 'SUBSCRIPTION_PAUSED'];
        yield 'revoked' => [12, 'SUBSCRIPTION_REVOKED'];
        yield 'expired' => [13, 'SUBSCRIPTION_EXPIRED'];
        yield 'unknown' => [99, 'UNKNOWN_99'];
    }

    #[Test]
    #[DataProvider('googleEventTypeProvider')]
    public function googlePlayPassesCorrectEventTypeToService(int $notificationType, string $expectedEventType): void
    {
        $capturedEventType = null;
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willReturn(null);

        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $logger = new NullLogger();

        // Use a custom logger to capture the event type from the "Webhook for unknown subscription" warning
        $captureLogger = new class ($capturedEventType) extends NullLogger {
            public function __construct(private mixed &$captured) {}

            /** @param array<string, mixed> $context */
            public function warning(string|Stringable $message, array $context = []): void
            {
                if (isset($context['event_type'])) {
                    $this->captured = $context['event_type'];
                }
            }
        };

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $webhookRepo,
            $captureLogger,
        );

        $controller = new WebhookController($service, $this->encryptionKey);

        $payload = json_encode([
            'subscriptionNotification' => [
                'notificationType' => $notificationType,
                'purchaseToken' => 'token',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/google-play',
            parsedBody: ['message' => ['data' => base64_encode($payload)]],
        );

        $controller->googlePlay($request);

        self::assertSame($expectedEventType, $capturedEventType);
    }

    // --- Apple webhook tests ---

    #[Test]
    public function appleSnsReturns400ForMissingSignedPayload(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/apple-sns',
            parsedBody: [],
        );

        $response = $controller->appleSns($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('signedPayload', $body['error']);
    }

    #[Test]
    public function appleSnsReturns400ForInvalidJws(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/apple-sns',
            parsedBody: ['signedPayload' => 'invalid-jws-no-dots'],
        );

        $response = $controller->appleSns($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function appleSnsReturns400WhenOriginalTransactionIdMissing(): void
    {
        $controller = $this->makeController();

        // Outer JWS: notification without data.signedTransactionInfo
        $outerPayload = json_encode([
            'notificationType' => 'DID_RENEW',
            'data' => [],
        ], JSON_THROW_ON_ERROR);

        $jws = $this->fakeJws($outerPayload);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/apple-sns',
            parsedBody: ['signedPayload' => $jws],
        );

        $response = $controller->appleSns($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('transaction ID', $body['error']);
    }

    #[Test]
    public function appleSnsReturnsOkForValidNotification(): void
    {
        $service = $this->makeService();
        $controller = new WebhookController($service, $this->encryptionKey);

        $transactionInfo = json_encode([
            'originalTransactionId' => 'txn-original-123',
            'productId' => 'com.example.premium',
        ], JSON_THROW_ON_ERROR);

        $innerJws = $this->fakeJws($transactionInfo);

        $outerPayload = json_encode([
            'notificationType' => 'DID_RENEW',
            'data' => [
                'signedTransactionInfo' => $innerJws,
            ],
        ], JSON_THROW_ON_ERROR);

        $outerJws = $this->fakeJws($outerPayload);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/apple-sns',
            parsedBody: ['signedPayload' => $outerJws],
        );

        $response = $controller->appleSns($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function appleSnsReturns500WhenProcessingFails(): void
    {
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willThrowException(new RuntimeException('fail'));

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new WebhookController($service, $this->encryptionKey);

        $transactionInfo = json_encode([
            'originalTransactionId' => 'txn-123',
        ], JSON_THROW_ON_ERROR);

        $innerJws = $this->fakeJws($transactionInfo);

        $outerPayload = json_encode([
            'notificationType' => 'DID_RENEW',
            'data' => ['signedTransactionInfo' => $innerJws],
        ], JSON_THROW_ON_ERROR);

        $outerJws = $this->fakeJws($outerPayload);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/webhooks/apple-sns',
            parsedBody: ['signedPayload' => $outerJws],
        );

        $response = $controller->appleSns($request);

        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * Create a fake JWS (header.payload.signature) with base64url-encoded segments.
     */
    private function fakeJws(string $payloadJson): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode($payloadJson);
        $signature = self::base64UrlEncode('fake-signature');

        return "$header.$payload.$signature";
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function makeController(): WebhookController
    {
        return new WebhookController($this->makeService(), $this->encryptionKey);
    }

    private function makeService(): SubscriptionService
    {
        return new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $this->createStub(SubscriptionRepositoryInterface::class),
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );
    }
}
