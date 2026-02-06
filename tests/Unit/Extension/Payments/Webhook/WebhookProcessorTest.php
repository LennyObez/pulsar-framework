<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Webhook;

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
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Extension\Payments\Webhook\WebhookProcessor;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Webhook\InMemoryWebhookEventLog;
use RuntimeException;

use function sprintf;

#[CoversClass(WebhookProcessor::class)]
final class WebhookProcessorTest extends TestCase
{
    private const string SECRET = 'whsec_test';
    private FixedClock $clock;
    private MetricRegistry $metricRegistry;
    private InMemoryWebhookEventLog $eventLog;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('@1700000000'));
        $this->metricRegistry = new MetricRegistry();
        $this->eventLog = new InMemoryWebhookEventLog();
    }

    #[Test]
    public function processValidWebhookSucceeds(): void
    {
        $handler = $this->createHandler();
        $processor = $this->createProcessor($handler);

        $body = $this->createEventBody('evt_1', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $response = $processor->process($body, $header);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('processed', (string) $response->getBody());
        self::assertCount(1, $handler->events);
    }

    #[Test]
    public function processInvalidSignatureReturns403(): void
    {
        $handler = $this->createHandler();
        $processor = $this->createProcessor($handler);

        $body = $this->createEventBody('evt_2', 'charge.failed');

        $response = $processor->process($body, 't=1700000000,v1=invalid');

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertCount(0, $handler->events);
    }

    #[Test]
    public function processReplayReturns200AlreadyProcessed(): void
    {
        $handler = $this->createHandler();
        $processor = $this->createProcessor($handler);

        $body = $this->createEventBody('evt_replay', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        // First request
        $processor->process($body, $header);

        // Second request — same event ID
        $response = $processor->process($body, $header);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('already_processed', (string) $response->getBody());
        self::assertCount(1, $handler->events); // Handler called only once
    }

    #[Test]
    public function processHandlerErrorReturns500AndReleasesEvent(): void
    {
        $handler = new class implements WebhookHandlerInterface {
            public function handle(WebhookEvent $event): void
            {
                throw new RuntimeException('Handler exploded');
            }
        };

        $processor = $this->createProcessor($handler);

        $body = $this->createEventBody('evt_fail', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $response = $processor->process($body, $header);

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());

        // Event should be released — retry allowed
        $body2 = $this->createEventBody('evt_fail', 'payment_intent.created');
        $header2 = $this->createSignatureHeader($body2, 1700000000);

        $handler2 = $this->createHandler();
        $processor2 = $this->createProcessor($handler2);

        $response2 = $processor2->process($body2, $header2);

        self::assertSame(ResponseStatus::OK->value, $response2->getStatusCode());
    }

    #[Test]
    public function metricsIncrementOnSuccess(): void
    {
        $handler = $this->createHandler();
        $processor = $this->createProcessor($handler);

        $body = $this->createEventBody('evt_m', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $processor->process($body, $header);

        $counter = $this->metricRegistry->counter('payments_webhooks_total');
        self::assertSame(1.0, $counter->value(new LabelSet([
            'provider' => 'null',
            'event_type' => 'payment_intent.created',
            'status' => 'ok',
        ])));
    }

    /**
     * @return object{events: list<WebhookEvent>}&WebhookHandlerInterface
     */
    private function createHandler(): WebhookHandlerInterface
    {
        return new class implements WebhookHandlerInterface {
            /** @var list<WebhookEvent> */
            public array $events = [];

            public function handle(WebhookEvent $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    private function createProcessor(WebhookHandlerInterface $handler): WebhookProcessor
    {
        return new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->clock),
            eventLog: $this->eventLog,
            handler: $handler,
            clock: $this->clock,
            metricRegistry: $this->metricRegistry,
            logger: new NullLogger(),
            config: $this->createConfig(),
        );
    }

    private function createConfig(): PaymentsConfig
    {
        return new PaymentsConfig(
            provider: 'null',
            defaultCurrency: 'USD',
            webhook: new WebhookConfig(
                secret: self::SECRET,
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

    private function createEventBody(string $id, string $type): string
    {
        return json_encode([
            'id' => $id,
            'type' => $type,
            'created_at' => 1700000000,
            'data' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function createSignatureHeader(string $body, int $timestamp): string
    {
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }
}
