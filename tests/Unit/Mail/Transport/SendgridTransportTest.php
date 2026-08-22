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
use Pulsar\Mail\Transport\Config\SendgridTransportConfig;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Mail\Transport\MailHttpResponse;
use Pulsar\Mail\Transport\SendgridTransport;
use RuntimeException;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SendgridTransport::class)]
final class SendgridTransportTest extends TestCase
{
    #[Test]
    public function sendsBasicMessage(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $responseBody = json_encode(['x-message-id' => 'sg-001'], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(202, $responseBody));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com')],
            subject: 'Test Subject',
            textBody: 'Hello',
        );

        $result = $transport->send($message);

        self::assertSame('sg-001', $result);
    }

    #[Test]
    public function nameReturnsSendgrid(): void
    {
        $config = new SendgridTransportConfig();
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $transport = new SendgridTransport($config, $httpClient);

        self::assertSame('sendgrid', $transport->name());
    }

    #[Test]
    public function sendsMessageWithAllFields(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $responseBody = json_encode(['x-message-id' => 'sg-002'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.sendgrid.com/v3/mail/send',
                self::callback(static function (array $headers): bool {
                    return ($headers['Authorization'] ?? '') === 'Bearer sg-key-abc'
                        && ($headers['Content-Type'] ?? '') === 'application/json';
                }),
                self::callback(static function (string $body): bool {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    /** @var array<int, array<string, mixed>> $personalizations */
                    $personalizations = $payload['personalizations'] ?? [];
                    /** @var array<string, mixed> $from */
                    $from = $payload['from'] ?? [];

                    return isset($personalizations[0]['to'])
                        && isset($personalizations[0]['cc'])
                        && isset($personalizations[0]['bcc'])
                        && ($from['email'] ?? '') === 'sender@example.com'
                        && ($from['name'] ?? '') === 'Sender'
                        && ($payload['subject'] ?? '') === 'Test Subject'
                        && isset($payload['reply_to'])
                        && isset($payload['headers'])
                        && isset($payload['attachments'])
                        && isset($payload['custom_args']);
                }),
            )
            ->willReturn(new MailHttpResponse(202, $responseBody));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com', 'Recipient')],
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

        self::assertSame('sg-002', $result);
    }

    #[Test]
    public function throwsOnHttpError(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(403, 'Forbidden'));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/sendgrid/');
        $transport->send($message);
    }

    #[Test]
    public function wrapsUnexpectedException(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('dns resolution failed'));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/dns resolution failed/');
        $transport->send($message);
    }

    #[Test]
    public function returnsEmptyStringWhenBodyEmpty(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(202, ''));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        self::assertSame('', $transport->send($message));
    }

    #[Test]
    public function returnsEmptyStringWhenNoMessageIdInResponse(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $responseBody = json_encode(['status' => 'accepted'], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(202, $responseBody));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        self::assertSame('', $transport->send($message));
    }

    #[Test]
    public function fromWithoutNameOmitsNameField(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $responseBody = json_encode(['x-message-id' => 'sg-003'], JSON_THROW_ON_ERROR);

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
                    /** @var array<string, mixed> $from */
                    $from = $payload['from'] ?? [];

                    return ($from['email'] ?? '') === 'sender@example.com'
                        && !isset($from['name']);
                }),
            )
            ->willReturn(new MailHttpResponse(202, $responseBody));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $transport->send($message);
    }

    #[Test]
    public function inlineAttachmentSetsDispositionInline(): void
    {
        $config = new SendgridTransportConfig(apiKey: 'sg-key-abc');
        $responseBody = json_encode(['x-message-id' => 'sg-004'], JSON_THROW_ON_ERROR);

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
                    $attachments = $payload['attachments'] ?? [];
                    $attachment = $attachments[0] ?? [];

                    return ($attachment['disposition'] ?? '') === 'inline'
                        && ($attachment['content_id'] ?? '') === 'logo-cid';
                }),
            )
            ->willReturn(new MailHttpResponse(202, $responseBody));

        $transport = new SendgridTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
            attachments: [new Attachment('logo.png', 'image-data', 'image/png', inline: true, cid: 'logo-cid')],
        );

        $transport->send($message);
    }
}
