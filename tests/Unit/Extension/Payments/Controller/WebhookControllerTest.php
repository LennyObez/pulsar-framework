<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Clock\FixedClock;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;
use Pulsar\Extension\Payments\Contract\WebhookHandlerInterface;
use Pulsar\Extension\Payments\Controller\WebhookController;
use Pulsar\Extension\Payments\Domain\WebhookEvent;
use Pulsar\Extension\Payments\Webhook\HmacWebhookVerifier;
use Pulsar\Extension\Payments\Webhook\InMemoryWebhookEventLog;
use Pulsar\Extension\Payments\Webhook\WebhookProcessor;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\MetricRegistry;

#[CoversClass(WebhookController::class)]
final class WebhookControllerTest extends TestCase
{
    #[Test]
    public function handleExtractsSignatureAndDelegatesToProcessor(): void
    {
        $secret = 'test-webhook-secret';
        $timestamp = 1735689600;
        $body = '{"id":"evt_1","type":"payment_intent.created","created_at":1000,"data":{}}';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $signatureHeader = 't=' . $timestamp . ',v1=' . $signature;

        $clock = new FixedClock(new DateTimeImmutable('@' . $timestamp));
        $handler = new class implements WebhookHandlerInterface {
            public bool $called = false;

            public function handle(WebhookEvent $event): void
            {
                $this->called = true;
            }
        };

        $config = $this->createConfig($secret);
        $processor = new WebhookProcessor(
            verifier: new HmacWebhookVerifier($clock),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            clock: $clock,
            metricRegistry: new MetricRegistry(),
            logger: new NullLogger(),
            config: $config,
        );

        $controller = new WebhookController($processor, $config);

        $request = new Request(
            method: Method::POST,
            uri: '/webhooks/payments',
            path: '/webhooks/payments',
            queryString: '',
            headers: new HeaderBag(['X-Payments-Signature' => $signatureHeader]),
            body: $body,
        );

        $response = $controller->handle($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertTrue($handler->called);
    }

    #[Test]
    public function handleUsesEmptyStringWhenSignatureHeaderMissing(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
        $handler = $this->createMock(WebhookHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $config = $this->createConfig('test-secret');
        $processor = new WebhookProcessor(
            verifier: new HmacWebhookVerifier($clock),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            clock: $clock,
            metricRegistry: new MetricRegistry(),
            logger: new NullLogger(),
            config: $config,
        );

        $controller = new WebhookController($processor, $config);

        $request = new Request(
            method: Method::POST,
            uri: '/webhooks/payments',
            path: '/webhooks/payments',
            queryString: '',
            headers: new HeaderBag([]),
            body: '{}',
        );

        $response = $controller->handle($request);

        // Empty signature causes verification failure → 403
        self::assertSame(ResponseStatus::Forbidden, $response->status);
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
