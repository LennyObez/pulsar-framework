<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Http\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Subscriptions\Http\Controller\SubscriptionController;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\VerificationResult;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

#[CoversClass(SubscriptionController::class)]
final class SubscriptionControllerTest extends TestCase
{
    #[Test]
    public function verifyReturns401WhenNoUserIdAttribute(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/subscriptions/verify');
        $response = $controller->verify($request);

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Authentication required', $body['error']);
    }

    #[Test]
    public function verifyReturns422ForInvalidStore(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/verify', 'user-1', [
            'store' => 'samsung',
            'purchase_token' => 'token',
            'plan' => 'premium',
        ]);

        $response = $controller->verify($request);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Invalid store', $body['error']);
    }

    #[Test]
    public function verifyReturns422ForMissingPurchaseToken(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/verify', 'user-1', [
            'store' => 'google',
            'purchase_token' => '',
            'plan' => 'premium',
        ]);

        $response = $controller->verify($request);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('purchase_token', $body['error']);
    }

    #[Test]
    public function verifyReturns422ForMissingPlan(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/verify', 'user-1', [
            'store' => 'apple',
            'purchase_token' => 'token',
            'plan' => '',
        ]);

        $response = $controller->verify($request);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('plan', $body['error']);
    }

    #[Test]
    public function verifyReturns201OnSuccess(): void
    {
        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn(new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'com.example.premium',
            autoRenewing: true,
        ));

        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $service = new SubscriptionService(
            $verifier,
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new SubscriptionController($service);

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/verify', 'user-1', [
            'store' => 'google',
            'purchase_token' => 'raw-token',
            'plan' => 'premium_monthly',
        ]);

        $response = $controller->verify($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
        self::assertArrayHasKey('subscription', $body);
        self::assertSame('active', $body['subscription']['status']);
    }

    #[Test]
    public function verifyReturns422WhenVerificationFails(): void
    {
        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn(VerificationResult::invalid());

        $service = new SubscriptionService(
            $verifier,
            $this->createStub(SubscriptionRepositoryInterface::class),
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new SubscriptionController($service);

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/verify', 'user-1', [
            'store' => 'google',
            'purchase_token' => 'bad-token',
            'plan' => 'premium',
        ]);

        $response = $controller->verify($request);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Purchase verification failed', $body['error']);
    }

    #[Test]
    public function statusReturns401WhenNoUser(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/subscriptions/status');
        $response = $controller->status($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function statusReturnsNoneWhenNoSubscription(): void
    {
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByUser')->willReturn(null);

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new SubscriptionController($service);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/subscriptions/status')
            ->withAttribute('user_id', 'user-1');

        $response = $controller->status($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('none', $body['status']);
        self::assertFalse($body['has_access']);
    }

    #[Test]
    public function statusReturnsSubscriptionDataWhenExists(): void
    {
        $subscription = $this->makeSampleSubscription();

        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByUser')->willReturn($subscription);

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new SubscriptionController($service);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/subscriptions/status')
            ->withAttribute('user_id', 'user-1');

        $response = $controller->status($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('active', $body['status']);
        self::assertTrue($body['has_access']);
        self::assertArrayHasKey('subscription', $body);
    }

    #[Test]
    public function restoreReturns401WhenNoUser(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/subscriptions/restore');
        $response = $controller->restore($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function restoreReturns422ForInvalidStore(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/restore', 'user-1', [
            'store' => 'windows',
            'purchase_token' => 'token',
            'plan' => 'plan',
        ]);

        $response = $controller->restore($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function restoreReturnsSubscriptionOnSuccess(): void
    {
        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn(new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'com.example.premium',
            autoRenewing: true,
        ));

        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $service = new SubscriptionService(
            $verifier,
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new SubscriptionController($service);

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/restore', 'user-1', [
            'store' => 'apple',
            'purchase_token' => 'restore-token',
            'plan' => 'premium',
        ]);

        $response = $controller->restore($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
        self::assertArrayHasKey('subscription', $body);
    }

    #[Test]
    public function restoreReturns422WhenVerificationFails(): void
    {
        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn(VerificationResult::invalid());

        $service = new SubscriptionService(
            $verifier,
            $this->createStub(SubscriptionRepositoryInterface::class),
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new SubscriptionController($service);

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/restore', 'user-1', [
            'store' => 'google',
            'purchase_token' => 'bad-token',
            'plan' => 'plan',
        ]);

        $response = $controller->restore($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function serializationOmitsSensitiveFields(): void
    {
        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn(new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'com.example.premium',
            autoRenewing: true,
        ));

        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $service = new SubscriptionService(
            $verifier,
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $controller = new SubscriptionController($service);

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/verify', 'user-1', [
            'store' => 'google',
            'purchase_token' => 'token',
            'plan' => 'premium',
        ]);

        $response = $controller->verify($request);
        $body = json_decode((string) $response->getBody(), true);

        self::assertArrayNotHasKey('purchase_token_hash', $body['subscription']);
        self::assertArrayNotHasKey('raw_receipt_encrypted', $body['subscription']);
        self::assertArrayHasKey('id', $body['subscription']);
        self::assertArrayHasKey('store', $body['subscription']);
        self::assertArrayHasKey('plan', $body['subscription']);
        self::assertArrayHasKey('has_access', $body['subscription']);
    }

    #[Test]
    public function restoreReturns422ForEmptyPurchaseToken(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/restore', 'user-1', [
            'store' => 'google',
            'purchase_token' => '',
            'plan' => 'plan',
        ]);

        $response = $controller->restore($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function restoreReturns422ForEmptyPlan(): void
    {
        $controller = new SubscriptionController($this->makeService());

        $request = $this->makeRequest('POST', '/api/v1/subscriptions/restore', 'user-1', [
            'store' => 'apple',
            'purchase_token' => 'token',
            'plan' => '',
        ]);

        $response = $controller->restore($request);

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(string $method, string $uri, string $userId, array $body): ServerRequest
    {
        $request = new ServerRequest(method: $method, uri: $uri, parsedBody: $body);

        return $request->withAttribute('user_id', $userId);
    }

    private function makeSampleSubscription(): Subscription
    {
        $now = new DateTimeImmutable();

        return new Subscription(
            id: 'sub-id-000000000000000ab',
            userId: 'user-1',
            store: Store::Google,
            productId: 'com.example.premium',
            plan: 'premium_monthly',
            status: SubscriptionStatus::Active,
            purchaseTokenHash: 'hash-secret',
            rawReceiptEncrypted: 'encrypted-receipt',
            originalTransactionId: 'txn-001',
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Create a SubscriptionService with no-op dependencies (for validation-only tests).
     */
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
