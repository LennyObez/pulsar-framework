<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Features\ProcessWebhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\BancontactConfig;
use Pulsar\Extension\Payments\Config\IdealConfig;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\KlarnaConfig;
use Pulsar\Extension\Payments\Config\MobileConfig;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\PayPalConfig;
use Pulsar\Extension\Payments\Config\SepaConfig;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;
use Pulsar\Extension\Payments\Contracts\WebhookHandlerInterface;
use Pulsar\Extension\Payments\Domain\WebhookEvent;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookRequest;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\InMemoryWebhookEventLog;
use Pulsar\Webhook\WebhookClaim;
use Pulsar\Webhook\WebhookEventLogInterface;
use RuntimeException;

use function sprintf;

#[CoversClass(ProcessWebhookHandler::class)]
final class ProcessWebhookHandlerTest extends TestCase
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
    public function executeValidWebhookSucceeds(): void
    {
        $handler = $this->createWebhookHandler();
        $processor = $this->createHandler($handler);

        $body = $this->createEventBody('evt_1', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $result = $processor->execute(new ProcessWebhookRequest($body, $header));

        self::assertSame(ResponseStatus::OK->value, $result->response->getStatusCode());
        self::assertStringContainsString('processed', (string) $result->response->getBody());
        self::assertCount(1, $handler->events);
    }

    #[Test]
    public function executeInvalidSignatureReturns403(): void
    {
        $handler = $this->createWebhookHandler();
        $processor = $this->createHandler($handler);

        $body = $this->createEventBody('evt_2', 'charge.failed');

        $result = $processor->execute(new ProcessWebhookRequest($body, 't=1700000000,v1=invalid'));

        self::assertSame(ResponseStatus::Forbidden->value, $result->response->getStatusCode());
        self::assertCount(0, $handler->events);
    }

    #[Test]
    public function executeReplayReturns200AlreadyProcessed(): void
    {
        $handler = $this->createWebhookHandler();
        $processor = $this->createHandler($handler);

        $body = $this->createEventBody('evt_replay', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $processor->execute(new ProcessWebhookRequest($body, $header));
        $result = $processor->execute(new ProcessWebhookRequest($body, $header));

        self::assertSame(ResponseStatus::OK->value, $result->response->getStatusCode());
        self::assertStringContainsString('already_processed', (string) $result->response->getBody());
        self::assertCount(1, $handler->events);
    }

    #[Test]
    public function executeHandlerErrorReturns500AndReleasesEvent(): void
    {
        $failingHandler = new class implements WebhookHandlerInterface {
            public function handle(WebhookEvent $event): void
            {
                throw new RuntimeException('Handler exploded');
            }
        };

        $processor = $this->createHandler($failingHandler);

        $body = $this->createEventBody('evt_fail', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $result = $processor->execute(new ProcessWebhookRequest($body, $header));

        self::assertSame(ResponseStatus::InternalServerError->value, $result->response->getStatusCode());
    }

    #[Test]
    public function metricsIncrementOnSuccess(): void
    {
        $handler = $this->createWebhookHandler();
        $processor = $this->createHandler($handler);

        $body = $this->createEventBody('evt_m', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $processor->execute(new ProcessWebhookRequest($body, $header));

        $counter = $this->metricRegistry->counter('payments_webhooks_total');
        self::assertSame(1.0, $counter->value(new LabelSet([
            'provider' => 'null',
            'event_type' => 'payment_intent.created',
            'status' => 'ok',
        ])));
    }

    #[Test]
    public function executeMalformedJsonReturns400(): void
    {
        $handler = $this->createWebhookHandler();
        $processor = $this->createHandler($handler);

        $body = 'not valid json{{{';
        $header = $this->createSignatureHeader($body, 1700000000);

        $result = $processor->execute(new ProcessWebhookRequest($body, $header));

        self::assertSame(ResponseStatus::BadRequest->value, $result->response->getStatusCode());
        self::assertStringContainsString('malformed_payload', (string) $result->response->getBody());
        self::assertCount(0, $handler->events);
    }

    #[Test]
    public function executeInvalidEventTypeReturns400(): void
    {
        $handler = $this->createWebhookHandler();
        $processor = $this->createHandler($handler);

        $body = json_encode([
            'id' => 'evt_bad',
            'type' => 'completely_invalid_type',
            'created_at' => 1700000000,
            'data' => [],
        ], JSON_THROW_ON_ERROR);
        $header = $this->createSignatureHeader($body, 1700000000);

        $result = $processor->execute(new ProcessWebhookRequest($body, $header));

        self::assertSame(ResponseStatus::BadRequest->value, $result->response->getStatusCode());
        self::assertStringContainsString('malformed_payload', (string) $result->response->getBody());
        self::assertCount(0, $handler->events);
    }

    #[Test]
    public function executeConcurrentClaimReturns409(): void
    {
        $handler = $this->createWebhookHandler();

        $concurrentEventLog = new class implements WebhookEventLogInterface {
            public function claim(string $eventId, DateTimeImmutable $now, int $ttlSeconds): WebhookClaim
            {
                throw WebhookException::concurrentClaim($eventId);
            }

            public function commit(string $eventId): void {}

            public function release(string $eventId): void {}

            public function prune(DateTimeImmutable $before): int
            {
                return 0;
            }
        };

        $processor = new ProcessWebhookHandler(
            verifier: new HmacWebhookVerifier($this->clock),
            eventLog: $concurrentEventLog,
            handler: $handler,
            clock: $this->clock,
            metricRegistry: $this->metricRegistry,
            logger: new \Psr\Log\NullLogger(),
            config: $this->createConfig(),
        );

        $body = $this->createEventBody('evt_concurrent', 'payment_intent.created');
        $header = $this->createSignatureHeader($body, 1700000000);

        $result = $processor->execute(new ProcessWebhookRequest($body, $header));

        self::assertSame(ResponseStatus::Conflict->value, $result->response->getStatusCode());
        self::assertStringContainsString('concurrent_processing', (string) $result->response->getBody());
        self::assertCount(0, $handler->events);
    }

    /**
     * @return object{events: list<WebhookEvent>}&WebhookHandlerInterface
     */
    private function createWebhookHandler(): WebhookHandlerInterface
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

    private function createHandler(WebhookHandlerInterface $handler): ProcessWebhookHandler
    {
        return new ProcessWebhookHandler(
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
            stripe: new StripeConfig(
                secretKey: '',
                publishableKey: '',
                webhookSecret: '',
                apiVersion: '2024-12-18.acacia',
                testMode: true,
            ),
            paypal: new PayPalConfig(
                clientId: '',
                clientSecret: '',
                webhookId: '',
                sandbox: true,
            ),
            sepa: SepaConfig::fromArray([]),
            mobile: MobileConfig::fromArray([]),
            payconiq: PayconiqConfig::fromArray([]),
            bancontact: BancontactConfig::fromArray([]),
            ideal: IdealConfig::fromArray([]),
            klarna: KlarnaConfig::fromArray([]),
            subscriptionsEnabled: false,
            invoiceRetentionDays: 3650,
            dunningMaxRetries: 4,
            trialMaxDays: 30,
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
