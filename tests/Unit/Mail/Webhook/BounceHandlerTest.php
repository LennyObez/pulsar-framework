<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Mail\Audit\DeliveryStatus;
use Pulsar\Mail\Webhook\BounceHandler;
use Pulsar\Mail\Webhook\WebhookRequest;

#[CoversClass(BounceHandler::class)]
final class BounceHandlerTest extends TestCase
{
    #[Test]
    public function processExtractsMessageIdAndBounceType(): void
    {
        $handler = new BounceHandler();
        $request = $this->createRequest('{"message_id":"msg-001","bounce_type":"hard"}');

        $result = $handler->process($request);

        self::assertSame('msg-001', $result['message_id']);
        self::assertSame('hard', $result['bounce_type']);
        self::assertSame(DeliveryStatus::Bounced, $result['delivery_status']);
    }

    #[Test]
    public function processHandlesMissingFields(): void
    {
        $handler = new BounceHandler();
        $request = $this->createRequest('{}');

        $result = $handler->process($request);

        self::assertNull($result['message_id']);
        self::assertSame('unknown', $result['bounce_type']);
        self::assertSame(DeliveryStatus::Bounced, $result['delivery_status']);
    }

    #[Test]
    public function processHandlesInvalidJson(): void
    {
        $handler = new BounceHandler();
        $request = $this->createRequest('not-json');

        $result = $handler->process($request);

        self::assertNull($result['message_id']);
        self::assertSame('unknown', $result['bounce_type']);
    }

    #[Test]
    public function processLogsToAuditor(): void
    {
        $auditor = $this->createMock(AuditLoggerInterface::class);
        $auditor->expects(self::once())
            ->method('log');

        $handler = new BounceHandler($auditor);
        $request = $this->createRequest('{"message_id":"msg-002","bounce_type":"soft"}');

        $handler->process($request);
    }

    #[Test]
    public function processWorksWithoutAuditor(): void
    {
        $handler = new BounceHandler(null);
        $request = $this->createRequest('{"message_id":"msg-003"}');

        $result = $handler->process($request);

        self::assertSame('msg-003', $result['message_id']);
    }

    #[Test]
    public function processHandlesNonStringMessageId(): void
    {
        $handler = new BounceHandler();
        $request = $this->createRequest('{"message_id":12345,"bounce_type":"hard"}');

        $result = $handler->process($request);

        self::assertNull($result['message_id']);
        self::assertSame('hard', $result['bounce_type']);
    }

    private function createRequest(string $payload): WebhookRequest
    {
        return new WebhookRequest(
            payload: $payload,
            headers: ['Content-Type' => 'application/json'],
            sourceIp: '10.0.0.1',
            timestamp: 1700000000,
            provider: 'mailgun',
        );
    }
}
