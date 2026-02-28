<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
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
use Pulsar\Http\Message\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SubscriptionControllerTest extends TestCase
{
    private SubscriptionVerifierInterface&Stub $verifier;
    private SubscriptionRepositoryInterface&Stub $subscriptionRepo;
    private WebhookEventRepositoryInterface&Stub $webhookRepo;
    private SubscriptionController $controller;

    protected function setUp(): void
    {
        $this->verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $this->subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);

        $service = new SubscriptionService(
            $this->verifier,
            $this->subscriptionRepo,
            $this->webhookRepo,
            new NullLogger(),
        );

        $this->controller = new SubscriptionController($service);
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
     * @param array<string, mixed>|null $body
     */
    private function makeRequest(
        ?string $userId = 'user-1',
        ?array $body = null,
    ): ServerRequestInterface&Stub {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($userId ?? '');
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    private function makeSubscription(
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?DateTimeImmutable $expiresAt = null,
    ): Subscription {
        $now = new DateTimeImmutable();

        return new Subscription(
            id: 'sub-001',
            userId: 'user-1',
            store: Store::Google,
            productId: 'premium_monthly',
            plan: 'Premium Monthly',
            status: $status,
            purchaseTokenHash: 'hash-abc',
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn-001',
            expiresAt: $expiresAt ?? new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    // --- verify() tests ---

    #[Test]
    public function verifyReturns201WithSubscriptionData(): void
    {
        $this->verifier->method('verify')->willReturn(new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'premium_monthly',
            autoRenewing: true,
        ));
        $this->subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $request = $this->makeRequest(body: [
            'store' => 'google',
            'purchase_token' => 'valid-token-123',
            'plan' => 'Premium Monthly',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('ok', $body['status']);
        $subscription = $body['subscription'];
        self::assertIsArray($subscription);
        self::assertSame('premium_monthly', $subscription['product_id']);
        self::assertSame('Premium Monthly', $subscription['plan']);
        self::assertSame('active', $subscription['status']);
    }

    #[Test]
    public function verifyReturns401WhenNoUserId(): void
    {
        $request = $this->makeRequest(userId: null, body: [
            'store' => 'google',
            'purchase_token' => 'token',
            'plan' => 'plan',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Authentication required', $body['error']);
    }

    #[Test]
    public function verifyReturns422ForInvalidStore(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 'windows',
            'purchase_token' => 'token',
            'plan' => 'plan',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid store', $error);
    }

    #[Test]
    public function verifyReturns422ForEmptyPurchaseToken(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 'google',
            'purchase_token' => '',
            'plan' => 'plan',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('purchase_token is required', $body['error']);
    }

    #[Test]
    public function verifyReturns422ForMissingPurchaseToken(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 'apple',
            'plan' => 'plan',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('purchase_token is required', $body['error']);
    }

    #[Test]
    public function verifyReturns422ForEmptyPlan(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 'google',
            'purchase_token' => 'token-123',
            'plan' => '',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('plan is required', $body['error']);
    }

    #[Test]
    public function verifyReturns422WhenVerificationFails(): void
    {
        $this->verifier->method('verify')->willReturn(VerificationResult::invalid());

        $request = $this->makeRequest(body: [
            'store' => 'google',
            'purchase_token' => 'bad-token',
            'plan' => 'Premium Monthly',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Purchase verification failed', $body['error']);
    }

    #[Test]
    public function verifyWithNullBodyTreatsFieldsAsEmpty(): void
    {
        $request = $this->makeRequest(body: null);

        $response = $this->controller->verify($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function verifyAcceptsAppleStore(): void
    {
        $this->verifier->method('verify')->willReturn(new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'premium_annual',
            autoRenewing: true,
        ));
        $this->subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $request = $this->makeRequest(body: [
            'store' => 'apple',
            'purchase_token' => 'apple-token-123',
            'plan' => 'Premium Annual',
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $subscription = $body['subscription'];
        self::assertIsArray($subscription);
        self::assertSame('apple', $subscription['store']);
    }

    #[Test]
    public function verifySerializationOmitsSensitiveFields(): void
    {
        $this->verifier->method('verify')->willReturn(new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'premium_monthly',
            autoRenewing: true,
        ));
        $this->subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $request = $this->makeRequest(body: [
            'store' => 'google',
            'purchase_token' => 'token-to-hash',
            'plan' => 'Premium',
        ]);

        $response = $this->controller->verify($request);

        $body = $this->jsonResponse($response);
        $subscription = $body['subscription'];
        self::assertIsArray($subscription);
        self::assertArrayNotHasKey('purchase_token_hash', $subscription);
        self::assertArrayNotHasKey('raw_receipt_encrypted', $subscription);
        self::assertArrayHasKey('id', $subscription);
        self::assertArrayHasKey('store', $subscription);
        self::assertArrayHasKey('product_id', $subscription);
        self::assertArrayHasKey('plan', $subscription);
        self::assertArrayHasKey('status', $subscription);
        self::assertArrayHasKey('has_access', $subscription);
        self::assertArrayHasKey('expires_at', $subscription);
        self::assertArrayHasKey('created_at', $subscription);
        self::assertArrayHasKey('updated_at', $subscription);
    }

    // --- status() tests ---

    #[Test]
    public function statusReturnsSubscriptionForAuthenticatedUser(): void
    {
        $sub = $this->makeSubscription();
        $this->subscriptionRepo->method('findByUser')->willReturn($sub);

        $request = $this->makeRequest();

        $response = $this->controller->status($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('active', $body['status']);
        self::assertTrue($body['has_access']);
        self::assertIsArray($body['subscription']);
    }

    #[Test]
    public function statusReturnsNoneWhenNoSubscription(): void
    {
        $this->subscriptionRepo->method('findByUser')->willReturn(null);

        $request = $this->makeRequest();

        $response = $this->controller->status($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('none', $body['status']);
        self::assertFalse($body['has_access']);
    }

    #[Test]
    public function statusReturns401WithoutAuthentication(): void
    {
        $request = $this->makeRequest(userId: null);

        $response = $this->controller->status($request);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Authentication required', $body['error']);
    }

    #[Test]
    public function statusReflectsExpiredSubscription(): void
    {
        $sub = $this->makeSubscription(SubscriptionStatus::Expired);
        $this->subscriptionRepo->method('findByUser')->willReturn($sub);

        $request = $this->makeRequest();

        $response = $this->controller->status($request);

        $body = $this->jsonResponse($response);
        self::assertSame('expired', $body['status']);
        self::assertFalse($body['has_access']);
    }

    // --- restore() tests ---

    #[Test]
    public function restoreReturns200WithRestoredSubscription(): void
    {
        $this->verifier->method('verify')->willReturn(new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'premium_annual',
            autoRenewing: true,
        ));
        $this->subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $request = $this->makeRequest(body: [
            'store' => 'apple',
            'purchase_token' => 'restore-token',
            'plan' => 'Premium Annual',
        ]);

        $response = $this->controller->restore($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('ok', $body['status']);
        $subscription = $body['subscription'];
        self::assertIsArray($subscription);
        self::assertSame('active', $subscription['status']);
    }

    #[Test]
    public function restoreReturns401WithoutAuthentication(): void
    {
        $request = $this->makeRequest(userId: null, body: [
            'store' => 'google',
            'purchase_token' => 'token',
            'plan' => 'plan',
        ]);

        $response = $this->controller->restore($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function restoreReturns422ForInvalidStore(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 'invalid',
            'purchase_token' => 'token',
            'plan' => 'plan',
        ]);

        $response = $this->controller->restore($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid store', $error);
    }

    #[Test]
    public function restoreReturns422ForEmptyPurchaseToken(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 'google',
            'purchase_token' => '',
            'plan' => 'plan',
        ]);

        $response = $this->controller->restore($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('purchase_token is required', $body['error']);
    }

    #[Test]
    public function restoreReturns422ForEmptyPlan(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 'apple',
            'purchase_token' => 'token-123',
            'plan' => '',
        ]);

        $response = $this->controller->restore($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('plan is required', $body['error']);
    }

    #[Test]
    public function restoreReturns422WhenVerificationFails(): void
    {
        $this->verifier->method('verify')->willReturn(VerificationResult::invalid());

        $request = $this->makeRequest(body: [
            'store' => 'apple',
            'purchase_token' => 'expired-token',
            'plan' => 'Premium',
        ]);

        $response = $this->controller->restore($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Purchase verification failed', $body['error']);
    }

    #[Test]
    public function restoreWithNullBodyReturns422(): void
    {
        $request = $this->makeRequest(body: null);

        $response = $this->controller->restore($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function verifyWithNonStringFieldsTreatsThemAsEmpty(): void
    {
        $request = $this->makeRequest(body: [
            'store' => 123,
            'purchase_token' => true,
            'plan' => ['nested'],
        ]);

        $response = $this->controller->verify($request);

        self::assertSame(422, $response->getStatusCode());
    }
}
