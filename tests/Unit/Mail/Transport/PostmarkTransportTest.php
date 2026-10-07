<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\PostmarkTransportConfig;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Mail\Transport\MailHttpResponse;
use Pulsar\Mail\Transport\PostmarkTransport;
use RuntimeException;

use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(PostmarkTransport::class)]
final class PostmarkTransportTest extends TestCase
{
    #[Test]
    public function sendsBasicMessage(): void
    {
        $config = new PostmarkTransportConfig(serverToken: 'token-xyz');
        $responseBody = json_encode(['MessageID' => 'pm-001'], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new PostmarkTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com')],
            subject: 'Test Subject',
            textBody: 'Hello',
        );

        $result = $transport->send($message);

        self::assertSame('pm-001', $result);
    }

    #[Test]
    public function nameReturnsPostmark(): void
    {
        $config = new PostmarkTransportConfig();
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $transport = new PostmarkTransport($config, $httpClient);

        self::assertSame('postmark', $transport->name());
    }

    #[Test]
    public function sendsMessageWithAllFields(): void
    {
        $config = new PostmarkTransportConfig(serverToken: 'token-xyz');
        $responseBody = json_encode(['MessageID' => 'pm-002'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.postmarkapp.com/email',
                self::callback(static function (array $headers): bool {
                    return ($headers['X-Postmark-Server-Token'] ?? '') === 'token-xyz'
                        && ($headers['Content-Type'] ?? '') === 'application/json';
                }),
                self::callback(static function (string $body): bool {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                    $to = $payload['To'] ?? '';

                    return ($payload['From'] ?? '') === 'Sender <sender@example.com>'
                        && is_string($to) && str_contains($to, 'recipient@example.com')
                        && ($payload['Subject'] ?? '') === 'Test Subject'
                        && ($payload['HtmlBody'] ?? '') === '<p>Hello</p>'
                        && ($payload['TextBody'] ?? '') === 'Hello'
                        && isset($payload['Cc'])
                        && isset($payload['Bcc'])
                        && ($payload['ReplyTo'] ?? '') === 'reply@example.com'
                        && isset($payload['Headers'])
                        && isset($payload['Attachments'])
                        && isset($payload['Metadata']);
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new PostmarkTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com')],
            subject: 'Test Subject',
            cc: [new Address('cc@example.com')],
            bcc: [new Address('bcc@example.com')],
            replyTo: new Address('reply@example.com'),
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
            attachments: [new Attachment('doc.pdf', 'PDF content', 'application/pdf')],
            headers: ['X-Custom' => 'value'],
            metadata: ['campaign' => 'promo'],
        );

        $result = $transport->send($message);

        self::assertSame('pm-002', $result);
    }

    #[Test]
    public function throwsOnHttpError(): void
    {
        $config = new PostmarkTransportConfig(serverToken: 'token-xyz');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(422, 'Unprocessable Entity'));

        $transport = new PostmarkTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/postmark/');
        $transport->send($message);
    }

    #[Test]
    public function wrapsUnexpectedException(): void
    {
        $config = new PostmarkTransportConfig(serverToken: 'token-xyz');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('timeout'));

        $transport = new PostmarkTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/timeout/');
        $transport->send($message);
    }

    #[Test]
    public function returnsEmptyStringWhenNoMessageIdInResponse(): void
    {
        $config = new PostmarkTransportConfig(serverToken: 'token-xyz');
        $responseBody = json_encode(['ErrorCode' => 0], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new PostmarkTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        self::assertSame('', $transport->send($message));
    }

    #[Test]
    public function fromWithoutNameUsesEmailOnly(): void
    {
        $config = new PostmarkTransportConfig(serverToken: 'token-xyz');
        $responseBody = json_encode(['MessageID' => 'pm-003'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (string $body): bool {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                    return ($payload['From'] ?? '') === 'sender@example.com';
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new PostmarkTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $transport->send($message);
    }

    #[Test]
    public function attachmentIncludesAllFields(): void
    {
        $config = new PostmarkTransportConfig(serverToken: 'token-xyz');
        $responseBody = json_encode(['MessageID' => 'pm-004'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (string $body): bool {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    /** @var list<array<string, mixed>> $attachments */
                    $attachments = $payload['Attachments'] ?? [];
                    $attachment = $attachments[0] ?? [];

                    return ($attachment['Name'] ?? '') === 'file.txt'
                        && ($attachment['ContentType'] ?? '') === 'text/plain'
                        && ($attachment['ContentID'] ?? '') === 'cid-123';
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new PostmarkTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
            attachments: [new Attachment('file.txt', 'content', 'text/plain', cid: 'cid-123')],
        );

        $transport->send($message);
    }
}
