<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Mail\Address;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\LogTransport;

#[CoversClass(LogTransport::class)]
final class LogTransportTest extends TestCase
{
    #[Test]
    public function it_logs_message_details(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Mail message sent via log transport',
                self::callback(function (array $context): bool {
                    return isset($context['message_id'])
                        && $context['subject'] === 'Test Subject'
                        && $context['from'] === 'Sender <sender@test.com>'
                        && $context['has_html'] === true
                        && $context['has_text'] === false
                        && $context['attachment_count'] === 0;
                }),
            );

        $transport = new LogTransport($logger);

        $message = new Message(
            from: new Address('sender@test.com', 'Sender'),
            to: [new Address('user@test.com')],
            subject: 'Test Subject',
            htmlBody: '<p>Hello</p>',
        );

        $messageId = $transport->send($message);

        self::assertStringStartsWith('<log-', $messageId);
        self::assertStringEndsWith('@localhost>', $messageId);
    }

    #[Test]
    public function it_reports_log_name(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $transport = new LogTransport($logger);

        self::assertSame('log', $transport->name());
    }

    #[Test]
    public function it_formats_address_without_name(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                self::anything(),
                self::callback(function (array $context): bool {
                    return $context['from'] === 'sender@test.com';
                }),
            );

        $transport = new LogTransport($logger);

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
        );

        $transport->send($message);
    }
}
