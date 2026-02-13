<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Subscriptions\Http\Controller\WebhookController;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function base64_encode;
use function json_decode;
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

#[CoversClass(WebhookController::class)]
final class WebhookControllerTest extends TestCase
{
    private SubscriptionVerifierInterface&Stub $verifier;
    private SubscriptionRepositoryInterface&Stub $subscriptionRepo;
    private WebhookEventRepositoryInterface&Stub $webhookRepo;
    private LoggerInterface&Stub $logger;
    private WebhookController $controller;

    protected function setUp(): void
    {
        $this->verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $this->subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService(
            $this->verifier,
            $this->subscriptionRepo,
            $this->webhookRepo,
            $this->logger,
        );

        $encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->controller = new WebhookController($service, $encryptionKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(Response $response): array
    {
        $decoded = json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Build a Google Play Pub/Sub-style webhook body.
     *
     * @param array<string, mixed> $notification
     * @return array<string, mixed>
     */
    private function buildGooglePayload(array $notification): array
    {
        $data = base64_encode(json_encode($notification, JSON_THROW_ON_ERROR));

        return [
            'message' => [
                'data' => $data,
                'messageId' => 'msg-123',
            ],
        ];
    }

    /**
     * Build an Apple App Store JWS-style signed payload.
     *
     * @param array<string, mixed> $outerPayload
     * @param array<string, mixed>|null $transactionInfo
     */
    private function buildApplePayload(array $outerPayload, ?array $transactionInfo = null): string
    {
        $header = base64_encode(json_encode(['alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $signature = base64_encode('fake-signature');

        if ($transactionInfo !== null) {
            $innerHeader = base64_encode(json_encode(['alg' => 'ES256'], JSON_THROW_ON_ERROR));
            $innerPayload = base64_encode(json_encode($transactionInfo, JSON_THROW_ON_ERROR));
            $innerSignature = base64_encode('inner-sig');
            $signedTransactionInfo = "{$innerHeader}.{$innerPayload}.{$innerSignature}";

            $outerPayload['data'] ??= [];
            /** @var array<string, mixed> $dataArray */
            $dataArray = $outerPayload['data'];
            $dataArray['signedTransactionInfo'] = $signedTransactionInfo;
            $outerPayload['data'] = $dataArray;
        }

        $payload = base64_encode(json_encode($outerPayload, JSON_THROW_ON_ERROR));

        return "{$header}.{$payload}.{$signature}";
    }

    private function buildSubscription(SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        $now = new DateTimeImmutable();

        return new Subscription(
            id: 'sub-001',
            userId: 'user-1',
            store: Store::Google,
            productId: 'com.example.premium',
            plan: 'monthly',
            status: $status,
            purchaseTokenHash: 'hash-123',
            rawReceiptEncrypted: null,
            originalTransactionId: 'original-txn-1',
            expiresAt: null,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    #[Test]
    public function googlePlayProcessesValidSubscriptionNotification(): void
    {
        $notification = [
            'subscriptionNotification' => [
                'notificationType' => 4,
                'purchaseToken' => 'google-purchase-token-abc',
            ],
        ];

        $body = $this->buildGooglePayload($notification);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        $response = $this->controller->googlePlay($request);

        self::assertSame(200, $response->getStatusCode());

        $responseBody = $this->jsonResponse($response);
        self::assertSame('ok', $responseBody['status']);
    }

    #[Test]
    public function googlePlayVerifiesWebhookEventIsSaved(): void
    {
        $notification = [
            'subscriptionNotification' => [
                'notificationType' => 4,
                'purchaseToken' => 'token-xyz',
            ],
        ];

        $body = $this->buildGooglePayload($notification);

        /** @var WebhookEventRepositoryInterface&MockObject $webhookRepo */
        $webhookRepo = $this->createMock(WebhookEventRepositoryInterface::class);
        $webhookRepo->expects(self::once())->method('save');

        $service = new SubscriptionService(
            $this->verifier,
            $this->subscriptionRepo,
            $webhookRepo,
            $this->logger,
        );

        $encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $controller = new WebhookController($service, $encryptionKey);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        $controller->googlePlay($request);
    }

    #[Test]
    public function appleProcessesValidSubscriptionNotification(): void
    {
        $outerPayload = [
            'notificationType' => 'DID_RENEW',
        ];

        $transactionInfo = [
            'originalTransactionId' => 'apple-txn-001',
            'productId' => 'com.example.premium',
        ];

        $signedPayload = $this->buildApplePayload($outerPayload, $transactionInfo);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'signedPayload' => $signedPayload,
        ]);

        $response = $this->controller->appleSns($request);

        self::assertSame(200, $response->getStatusCode());

        $responseBody = $this->jsonResponse($response);
        self::assertSame('ok', $responseBody['status']);
    }

    #[Test]
    public function appleVerifiesTransactionIdPassedToRepository(): void
    {
        $outerPayload = [
            'notificationType' => 'SUBSCRIBED',
        ];

        $transactionInfo = [
            'originalTransactionId' => 'apple-txn-002',
        ];

        $signedPayload = $this->buildApplePayload($outerPayload, $transactionInfo);

        /** @var SubscriptionRepositoryInterface&MockObject $subscriptionRepo */
        $subscriptionRepo = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->expects(self::once())
            ->method('findByOriginalTransactionId')
            ->with('apple-txn-002')
            ->willReturn(null);

        $service = new SubscriptionService(
            $this->verifier,
            $subscriptionRepo,
            $this->webhookRepo,
            $this->logger,
        );

        $encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $controller = new WebhookController($service, $encryptionKey);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'signedPayload' => $signedPayload,
        ]);

        $controller->appleSns($request);
    }

    #[Test]
    public function googlePlayWithMissingMessageReturns400(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $response = $this->controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid Pub/Sub message format', $error);
    }

    #[Test]
    public function googlePlayWithMissingDataReturns400(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'message' => ['messageId' => 'msg-1'],
        ]);

        $response = $this->controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Missing message data', $error);
    }

    #[Test]
    public function googlePlayWithInvalidBase64Returns400(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'message' => ['data' => '!!!invalid-base64!!!'],
        ]);

        $response = $this->controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid base64 payload', $error);
    }

    #[Test]
    public function googlePlayWithInvalidJsonReturns400(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'message' => ['data' => base64_encode('not-json{{{')],
        ]);

        $response = $this->controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid JSON payload', $error);
    }

    #[Test]
    public function googlePlayWithMissingPurchaseTokenReturns400(): void
    {
        $notification = [
            'subscriptionNotification' => [
                'notificationType' => 4,
            ],
        ];

        $body = $this->buildGooglePayload($notification);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        $response = $this->controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());

        $responseBody = $this->jsonResponse($response);
        $error = $responseBody['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Missing purchase token', $error);
    }

    #[Test]
    public function appleMissingSignedPayloadReturns400(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $response = $this->controller->appleSns($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Missing signedPayload', $error);
    }

    #[Test]
    public function appleInvalidJwsFormatReturns400(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'signedPayload' => 'not-a-jws',
        ]);

        $response = $this->controller->appleSns($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid JWS payload', $error);
    }

    #[Test]
    public function appleMissingOriginalTransactionIdReturns400(): void
    {
        $outerPayload = [
            'notificationType' => 'DID_RENEW',
            'data' => [
                'signedTransactionInfo' => $this->buildApplePayload(['productId' => 'com.example.app']),
            ],
        ];

        $header = base64_encode(json_encode(['alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode($outerPayload, JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');
        $signedPayload = "{$header}.{$payload}.{$signature}";

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'signedPayload' => $signedPayload,
        ]);

        $response = $this->controller->appleSns($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Missing original transaction ID', $error);
    }

    #[Test]
    public function googlePlayNonSubscriptionNotificationIsIgnored(): void
    {
        $notification = [
            'oneTimeProductNotification' => [
                'notificationType' => 1,
                'purchaseToken' => 'one-time-token',
            ],
        ];

        $body = $this->buildGooglePayload($notification);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        $response = $this->controller->googlePlay($request);

        self::assertSame(200, $response->getStatusCode());

        $responseBody = $this->jsonResponse($response);
        self::assertSame('ignored', $responseBody['status']);
    }

    #[Test]
    public function googlePlayProcessingFailureReturns500(): void
    {
        $notification = [
            'subscriptionNotification' => [
                'notificationType' => 2,
                'purchaseToken' => 'fail-token',
            ],
        ];

        $body = $this->buildGooglePayload($notification);

        /** @var WebhookEventRepositoryInterface&Stub $failingWebhookRepo */
        $failingWebhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $failingWebhookRepo->method('save')
            ->willThrowException(new RuntimeException('Database error'));

        $service = new SubscriptionService(
            $this->verifier,
            $this->subscriptionRepo,
            $failingWebhookRepo,
            $this->logger,
        );

        $encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $controller = new WebhookController($service, $encryptionKey);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        $response = $controller->googlePlay($request);

        self::assertSame(500, $response->getStatusCode());

        $responseBody = $this->jsonResponse($response);
        self::assertSame('Processing failed', $responseBody['error']);
    }

    #[Test]
    public function appleProcessingFailureReturns500(): void
    {
        $outerPayload = [
            'notificationType' => 'EXPIRED',
        ];

        $transactionInfo = [
            'originalTransactionId' => 'apple-txn-fail',
        ];

        $signedPayload = $this->buildApplePayload($outerPayload, $transactionInfo);

        /** @var WebhookEventRepositoryInterface&Stub $failingWebhookRepo */
        $failingWebhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $failingWebhookRepo->method('save')
            ->willThrowException(new RuntimeException('Service unavailable'));

        $service = new SubscriptionService(
            $this->verifier,
            $this->subscriptionRepo,
            $failingWebhookRepo,
            $this->logger,
        );

        $encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $controller = new WebhookController($service, $encryptionKey);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'signedPayload' => $signedPayload,
        ]);

        $response = $controller->appleSns($request);

        self::assertSame(500, $response->getStatusCode());

        $responseBody = $this->jsonResponse($response);
        self::assertSame('Processing failed', $responseBody['error']);
    }

    #[Test]
    public function googlePlayCancellationUpdatesSubscriptionStatus(): void
    {
        $existing = $this->buildSubscription(SubscriptionStatus::Active);

        $notification = [
            'subscriptionNotification' => [
                'notificationType' => 3, // SUBSCRIPTION_CANCELED
                'purchaseToken' => 'cancel-token',
            ],
        ];

        $body = $this->buildGooglePayload($notification);

        /** @var SubscriptionRepositoryInterface&MockObject $subscriptionRepo */
        $subscriptionRepo = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->expects(self::once())
            ->method('findByOriginalTransactionId')
            ->with('cancel-token')
            ->willReturn($existing);
        $subscriptionRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn(Subscription $s): bool => $s->status === SubscriptionStatus::Cancelled,
            ));

        $service = new SubscriptionService(
            $this->verifier,
            $subscriptionRepo,
            $this->webhookRepo,
            $this->logger,
        );

        $encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $controller = new WebhookController($service, $encryptionKey);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        $response = $controller->googlePlay($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function appleRefundUpdatesSubscriptionToRevokedStatus(): void
    {
        $existing = $this->buildSubscription(SubscriptionStatus::Active);

        $outerPayload = [
            'notificationType' => 'REFUND',
        ];

        $transactionInfo = [
            'originalTransactionId' => 'apple-refund-txn',
        ];

        $signedPayload = $this->buildApplePayload($outerPayload, $transactionInfo);

        /** @var SubscriptionRepositoryInterface&MockObject $subscriptionRepo */
        $subscriptionRepo = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->expects(self::once())
            ->method('findByOriginalTransactionId')
            ->with('apple-refund-txn')
            ->willReturn($existing);
        $subscriptionRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn(Subscription $s): bool => $s->status === SubscriptionStatus::Revoked,
            ));

        $service = new SubscriptionService(
            $this->verifier,
            $subscriptionRepo,
            $this->webhookRepo,
            $this->logger,
        );

        $encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $controller = new WebhookController($service, $encryptionKey);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'signedPayload' => $signedPayload,
        ]);

        $response = $controller->appleSns($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function googlePlayWithNullBodyReturns400(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $response = $this->controller->googlePlay($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid Pub/Sub message format', $error);
    }
}
