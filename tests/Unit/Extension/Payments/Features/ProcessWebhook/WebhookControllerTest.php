<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Features\ProcessWebhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;
use Pulsar\Extension\Payments\Contracts\WebhookHandlerInterface;
use Pulsar\Extension\Payments\Domain\WebhookEvent;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\WebhookController;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Webhook\InMemoryWebhookEventLog;

#[CoversClass(WebhookController::class)]
final class WebhookControllerTest extends TestCase
{
    #[Test]
    public function handleExtractsSignatureAndDelegatesToHandler(): void
    {
        $secret = 'test-webhook-secret';
        $timestamp = 1735689600;
        $body = '{"id":"evt_1","type":"payment_intent.created","created_at":1000,"data":{}}';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $signatureHeader = 't=' . $timestamp . ',v1=' . $signature;

        $clock = new FixedClock(new DateTimeImmutable('@' . $timestamp));
        $webhookHandler = new class implements WebhookHandlerInterface {
            public bool $called = false;

            public function handle(WebhookEvent $event): void
            {
                $this->called = true;
            }
        };

        $config = $this->createConfig($secret);
        $processHandler = new ProcessWebhookHandler(
            verifier: new HmacWebhookVerifier($clock),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $webhookHandler,
            clock: $clock,
            metricRegistry: new MetricRegistry(),
            logger: new NullLogger(),
            config: $config,
        );

        $controller = new WebhookController($processHandler, $config);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/webhooks/payments',
            headers: ['X-Payments-Signature' => $signatureHeader],
            body: $body,
        );

        $response = $controller->handle($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertTrue($webhookHandler->called);
    }

    #[Test]
    public function handleUsesEmptyStringWhenSignatureHeaderMissing(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
        $webhookHandler = $this->createMock(WebhookHandlerInterface::class);
        $webhookHandler->expects(self::never())->method('handle');

        $config = $this->createConfig('test-secret');
        $processHandler = new ProcessWebhookHandler(
            verifier: new HmacWebhookVerifier($clock),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $webhookHandler,
            clock: $clock,
            metricRegistry: new MetricRegistry(),
            logger: new NullLogger(),
            config: $config,
        );

        $controller = new WebhookController($processHandler, $config);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/webhooks/payments',
            body: '{}',
        );

        $response = $controller->handle($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    private function createConfig(string $secret): PaymentsConfig
    {
        return new PaymentsConfig(
            provider: 'null',
            defaultCurrency: 'USD',
            webhook: new WebhookConfig(
                secret: $secret,
                path: '/webhooks/payments',
                toleranceSeconds: 300,
                signatureHeader: 'X-Payments-Signature',
            ),
            idempotency: new IdempotencyConfig(
                ttlSeconds: 86400,
                store: 'memory',
                maxKeyLength: 256,
            ),
            webhookLog: new WebhookLogConfig(
                ttlSeconds: 259200,
                store: 'memory',
            ),
        );
    }
}
