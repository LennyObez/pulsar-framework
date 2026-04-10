<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\HmacWebhookVerifier;
use Pulsar\Webhook\InMemoryWebhookEventLog;
use Pulsar\Webhook\WebhookHandlerInterface;
use Pulsar\Webhook\WebhookProcessingStatus;
use Pulsar\Webhook\WebhookProcessor;
use RuntimeException;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

#[CoversClass(WebhookProcessor::class)]
final class WebhookProcessorTest extends TestCase
{
    private const string SECRET = 'whsec_processor_test';
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('@1700000000');
    }

    #[Test]
    public function processValidWebhookReturnsProcessed(): void
    {
        $handler = $this->createMock(WebhookHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with('payment.created', $this->isArray());

        $processor = $this->createProcessor($handler);
        $payload = json_encode(['id' => 'evt-1', 'type' => 'payment.created'], JSON_THROW_ON_ERROR);
        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::Processed, $result->status);
        self::assertSame('evt-1', $result->eventId);
        self::assertNull($result->error);
    }

    #[Test]
    public function processInvalidSignatureReturnsInvalidSignature(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = $this->createProcessor($handler);

        $payload = json_encode(['id' => 'evt-1', 'type' => 'test'], JSON_THROW_ON_ERROR);
        $header = sprintf('t=%d,v1=%s', $this->now->getTimestamp(), 'invalid_signature');

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::InvalidSignature, $result->status);
    }

    #[Test]
    public function processReplayReturnsReplay(): void
    {
        $handler = $this->createMock(WebhookHandlerInterface::class);
        $handler->expects($this->once())->method('handle');

        $eventLog = new InMemoryWebhookEventLog();
        $processor = $this->createProcessor($handler, $eventLog);

        $payload = json_encode(['id' => 'evt-1', 'type' => 'test'], JSON_THROW_ON_ERROR);
        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        // First call processes
        (void) $processor->process($payload, $header, $this->now);

        // Second call is a replay
        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::Replay, $result->status);
        self::assertSame('evt-1', $result->eventId);
    }

    #[Test]
    public function processHandlerErrorReleasesClaimAndReturnsError(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('Handler boom'));

        $eventLog = new InMemoryWebhookEventLog();
        $processor = $this->createProcessor($handler, $eventLog);

        $payload = json_encode(['id' => 'evt-1', 'type' => 'test'], JSON_THROW_ON_ERROR);
        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('evt-1', $result->eventId);
        self::assertSame('Handler boom', $result->error);
    }

    #[Test]
    public function processMissingEventIdReturnsHandlerError(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = $this->createProcessor($handler);

        $payload = json_encode(['type' => 'test'], JSON_THROW_ON_ERROR); // No 'id' key
        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('Missing event ID in payload', $result->error);
    }

    #[Test]
    public function processInvalidJsonReturnsHandlerError(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = $this->createProcessor($handler);

        $payload = 'not-json';
        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('Invalid JSON payload', $result->error);
    }

    #[Test]
    public function constructorRejectsEmptySecret(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Webhook secret cannot be empty');

        new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            secret: '',
        );
    }

    #[Test]
    public function verifierRejectsEmptySecret(): void
    {
        $verifier = new HmacWebhookVerifier($this->now);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Webhook secret cannot be empty');

        $verifier->verify('payload', 't=1,v1=deadbeef', '', 300);
    }

    #[Test]
    public function processRejectsBodyExceedingSizeLimit(): void
    {
        // F25.6: a body larger than the configured cap must be
        // rejected before signature verification (prevents both DoS
        // and the secondary OOM via json_decode on a 10 GB blob).
        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            secret: self::SECRET,
            maxBodyBytes: 64,
        );

        $oversized = str_repeat('a', 65); // 1 byte over the cap
        $header = $this->buildSignatureHeader($oversized, $this->now->getTimestamp());

        $result = $processor->process($oversized, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('Webhook payload exceeds size limit', $result->error);
    }

    #[Test]
    public function processRejectsDeeplyNestedJson(): void
    {
        // F25.6: cap json_decode depth at 32 — defends against
        // small-but-deeply-nested JSON-bomb payloads that fit under
        // the byte cap but consume excessive parser resources.
        // Build a JSON object nested 50 levels deep.
        $payload = str_repeat('{"x":', 50) . '1' . str_repeat('}', 50);

        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = $this->createProcessor($handler);

        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('Invalid JSON payload', $result->error);
    }

    private function createProcessor(
        WebhookHandlerInterface $handler,
        ?InMemoryWebhookEventLog $eventLog = null,
    ): WebhookProcessor {
        return new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: $eventLog ?? new InMemoryWebhookEventLog(),
            handler: $handler,
            secret: self::SECRET,
        );
    }

    private function buildSignatureHeader(string $payload, int $timestamp): string
    {
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }
}
