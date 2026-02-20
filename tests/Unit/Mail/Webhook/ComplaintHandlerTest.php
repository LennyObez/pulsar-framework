<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Mail\Webhook\ComplaintHandler;
use Pulsar\Mail\Webhook\WebhookRequest;

#[CoversClass(ComplaintHandler::class)]
final class ComplaintHandlerTest extends TestCase
{
    #[Test]
    public function processExtractsMessageIdAndComplaintType(): void
    {
        $handler = new ComplaintHandler();
        $request = $this->createRequest('{"message_id":"msg-001","complaint_type":"abuse"}');

        $result = $handler->process($request);

        self::assertSame('msg-001', $result['message_id']);
        self::assertSame('abuse', $result['complaint_type']);
        self::assertTrue($result['should_unsubscribe']);
    }

    #[Test]
    #[DataProvider('complaintTypeProvider')]
    public function shouldUnsubscribeLogicIsCorrect(string $type, bool $expected): void
    {
        $handler = new ComplaintHandler();
        $request = $this->createRequest(json_encode(['complaint_type' => $type]));

        $result = $handler->process($request);

        self::assertSame($expected, $result['should_unsubscribe']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function complaintTypeProvider(): iterable
    {
        yield 'abuse triggers unsubscribe' => ['abuse', true];
        yield 'spam triggers unsubscribe' => ['spam', true];
        yield 'other does not trigger' => ['other', false];
        yield 'feedback does not trigger' => ['feedback', false];
    }

    #[Test]
    public function processHandlesMissingFields(): void
    {
        $handler = new ComplaintHandler();
        $request = $this->createRequest('{}');

        $result = $handler->process($request);

        self::assertNull($result['message_id']);
        self::assertSame('abuse', $result['complaint_type']);
        self::assertTrue($result['should_unsubscribe']);
    }

    #[Test]
    public function processLogsToAuditor(): void
    {
        $auditor = $this->createMock(AuditLoggerInterface::class);
        $auditor->expects(self::once())
            ->method('log');

        $handler = new ComplaintHandler($auditor);
        $request = $this->createRequest('{"complaint_type":"spam"}');

        $handler->process($request);
    }

    #[Test]
    public function processWorksWithoutAuditor(): void
    {
        $handler = new ComplaintHandler(null);
        $request = $this->createRequest('{"message_id":"msg-003"}');

        $result = $handler->process($request);

        self::assertSame('msg-003', $result['message_id']);
    }

    private function createRequest(string $payload): WebhookRequest
    {
        return new WebhookRequest(
            payload: $payload,
            headers: ['Content-Type' => 'application/json'],
            sourceIp: '10.0.0.1',
            timestamp: 1700000000,
            provider: 'sendgrid',
        );
    }
}
