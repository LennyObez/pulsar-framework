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
use Pulsar\Webhook\WebhookClaim;
use Pulsar\Webhook\WebhookEventLogInterface;
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
        $this->expectExceptionMessageIsOrContains('Webhook secret cannot be empty');

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
        $this->expectExceptionMessageIsOrContains('Webhook secret cannot be empty');

        $verifier->verify('payload', 't=1,v1=deadbeef', '', 300);
    }

    #[Test]
    public function processRejectsBodyExceedingSizeLimit(): void
    {
        // A body larger than the configured cap must be rejected before
        // signature verification — otherwise an unauthenticated caller
        // pays for an HMAC over an arbitrarily large blob, and a later
        // json_decode over the same blob exhausts memory.
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
        // json_decode depth is capped at 32: a small-but-deeply-nested
        // JSON bomb fits comfortably under the byte cap while consuming
        // parser resources out of proportion to its size.
        // Build a JSON object nested 50 levels deep.
        $payload = str_repeat('{"x":', 50) . '1' . str_repeat('}', 50);

        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = $this->createProcessor($handler);

        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('Invalid JSON payload', $result->error);
    }

    #[Test]
    public function processCommitFailureReleasesClaimAndReturnsHandlerError(): void
    {
        // A persistent event log may throw from commit() (duplicate key,
        // connection loss) AFTER the handler has already run. The processor
        // must not let that exception escape: it releases the in-flight claim
        // (so a retry can re-process cleanly) and returns a HandlerError result.
        $handler = $this->createMock(WebhookHandlerInterface::class);
        $handler->expects($this->once())->method('handle');

        $eventLog = new class implements WebhookEventLogInterface {
            public bool $released = false;

            public function claim(string $eventId, DateTimeImmutable $now, int $ttlSeconds): WebhookClaim
            {
                return WebhookClaim::claimed();
            }

            public function commit(string $eventId): void
            {
                throw new RuntimeException('commit boom');
            }

            public function release(string $eventId): void
            {
                $this->released = true;
            }

            public function prune(DateTimeImmutable $before): int
            {
                return 0;
            }
        };

        $processor = new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: $eventLog,
            handler: $handler,
            secret: self::SECRET,
        );

        $payload = json_encode(['id' => 'evt-commit', 'type' => 'test'], JSON_THROW_ON_ERROR);
        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('evt-commit', $result->eventId);
        self::assertSame('Event log commit failed: commit boom', $result->error);
        self::assertTrue($eventLog->released, 'commit failure must release the in-flight claim');
    }

    #[Test]
    public function processConcurrentClaimReturnsReplay(): void
    {
        // claim() throwing WebhookException (a concurrent in-flight claim)
        // is mapped to Replay; the handler must never run in that case.
        $handler = $this->createMock(WebhookHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $eventLog = new class implements WebhookEventLogInterface {
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

        $processor = new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: $eventLog,
            handler: $handler,
            secret: self::SECRET,
        );

        $payload = json_encode(['id' => 'evt-race', 'type' => 'test'], JSON_THROW_ON_ERROR);
        $header = $this->buildSignatureHeader($payload, $this->now->getTimestamp());

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::Replay, $result->status);
        self::assertSame('evt-race', $result->eventId);
    }

    #[Test]
    public function invalidSignatureResultCarriesFailureReason(): void
    {
        // The InvalidSignature path now populates the error field so callers
        // can audit the failure without an external trace. The message must
        // not be null and must reflect the verifier's generic failure string.
        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = $this->createProcessor($handler);

        $payload = json_encode(['id' => 'evt-1', 'type' => 'test'], JSON_THROW_ON_ERROR);
        // 64 hex chars so it passes header parsing but is not the real HMAC.
        $bogus = str_repeat('a', 64);
        $header = sprintf('t=%d,v1=%s', $this->now->getTimestamp(), $bogus);

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::InvalidSignature, $result->status);
        self::assertSame('Webhook signature verification failed', $result->error);
    }

    #[Test]
    public function zeroToleranceRejectsAnyClockSkew(): void
    {
        // toleranceSeconds = 0 is a valid (strictest) configuration: an exact
        // timestamp match passes, any skew fails verification.
        $handler = $this->createStub(WebhookHandlerInterface::class);
        $processor = new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            secret: self::SECRET,
            toleranceSeconds: 0,
        );

        $payload = json_encode(['id' => 'evt-skew', 'type' => 'test'], JSON_THROW_ON_ERROR);
        // Sign with a timestamp one second in the past — 1s of skew, zero tolerance.
        $skewed = $this->now->getTimestamp() - 1;
        $header = $this->buildSignatureHeader($payload, $skewed);

        $result = $processor->process($payload, $header, $this->now);

        self::assertSame(WebhookProcessingStatus::InvalidSignature, $result->status);
    }

    #[Test]
    public function constructorRejectsNonPositiveMaxBodyBytes(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('maxBodyBytes');

        new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            secret: self::SECRET,
            maxBodyBytes: 0,
        );
    }

    #[Test]
    public function constructorRejectsNegativeTolerance(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('toleranceSeconds');

        new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            secret: self::SECRET,
            toleranceSeconds: -1,
        );
    }

    #[Test]
    public function constructorRejectsNonPositiveDeduplicationTtl(): void
    {
        $handler = $this->createStub(WebhookHandlerInterface::class);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('deduplicationTtlSeconds');

        new WebhookProcessor(
            verifier: new HmacWebhookVerifier($this->now),
            eventLog: new InMemoryWebhookEventLog(),
            handler: $handler,
            secret: self::SECRET,
            deduplicationTtlSeconds: 0,
        );
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
