<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\InMemoryDeduplicationStore;
use Pulsar\Mail\Webhook\WebhookEventType;
use Pulsar\Mail\Webhook\WebhookHandler;
use Pulsar\Mail\Webhook\WebhookRequest;
use Pulsar\Mail\Webhook\WebhookVerifierInterface;

use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

#[CoversClass(WebhookHandler::class)]
final class WebhookHandlerTest extends TestCase
{
    #[Test]
    public function it_rejects_invalid_signature(): void
    {
        $verifier = $this->createStub(WebhookVerifierInterface::class);
        $verifier->method('verify')->willReturn(false);

        $handler = new WebhookHandler($verifier, new InMemoryDeduplicationStore());

        $request = new WebhookRequest(
            payload: '{"event_id":"evt-1"}',
            headers: [],
            sourceIp: '1.2.3.4',
            timestamp: time(),
            provider: 'ses',
        );

        $result = $handler->handle($request);

        self::assertFalse($result->accepted);
    }

    #[Test]
    public function it_accepts_valid_request(): void
    {
        $verifier = $this->createStub(WebhookVerifierInterface::class);
        $verifier->method('verify')->willReturn(true);

        $handler = new WebhookHandler($verifier, new InMemoryDeduplicationStore());

        $payload = json_encode([
            'event_id' => 'evt-1',
            'event_type' => 'bounce',
            'message_id' => 'msg-001',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest(
            payload: $payload,
            headers: [],
            sourceIp: '1.2.3.4',
            timestamp: time(),
            provider: 'ses',
        );

        $result = $handler->handle($request);

        self::assertTrue($result->accepted);
        self::assertSame('evt-1', $result->eventId);
        self::assertSame(WebhookEventType::Bounce, $result->eventType);
        self::assertSame('msg-001', $result->messageId);
    }

    #[Test]
    public function it_deduplicates_events(): void
    {
        $verifier = $this->createStub(WebhookVerifierInterface::class);
        $verifier->method('verify')->willReturn(true);

        $dedup = new InMemoryDeduplicationStore();
        $handler = new WebhookHandler($verifier, $dedup);

        $payload = json_encode([
            'event_id' => 'evt-dup',
            'event_type' => 'delivery',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest(
            payload: $payload,
            headers: [],
            sourceIp: '1.2.3.4',
            timestamp: time(),
            provider: 'ses',
        );

        // First call stores the event
        $result1 = $handler->handle($request);
        self::assertTrue($result1->accepted);

        // Second call deduplicates
        $result2 = $handler->handle($request);
        self::assertTrue($result2->accepted);
        self::assertSame('evt-dup', $result2->eventId);
    }

    #[Test]
    public function it_rejects_stale_timestamps(): void
    {
        $verifier = $this->createStub(WebhookVerifierInterface::class);
        $verifier->method('verify')->willReturn(true);

        $handler = new WebhookHandler(
            $verifier,
            new InMemoryDeduplicationStore(),
            replayWindowSeconds: 300,
        );

        $request = new WebhookRequest(
            payload: '{"event_id":"evt-stale"}',
            headers: [],
            sourceIp: '1.2.3.4',
            timestamp: time() - 600, // 10 minutes ago, window is 5 minutes
            provider: 'ses',
        );

        $result = $handler->handle($request);

        self::assertFalse($result->accepted);
    }

    #[Test]
    public function it_defaults_to_delivery_event_type(): void
    {
        $verifier = $this->createStub(WebhookVerifierInterface::class);
        $verifier->method('verify')->willReturn(true);

        $handler = new WebhookHandler($verifier, new InMemoryDeduplicationStore());

        $payload = json_encode([
            'event_id' => 'evt-2',
            'event_type' => 'unknown_type',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest(
            payload: $payload,
            headers: [],
            sourceIp: '1.2.3.4',
            timestamp: time(),
            provider: 'mailgun',
        );

        $result = $handler->handle($request);

        self::assertTrue($result->accepted);
        self::assertSame(WebhookEventType::Delivery, $result->eventType);
    }
}
