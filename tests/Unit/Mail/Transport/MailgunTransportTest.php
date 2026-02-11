<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\MailgunTransportConfig;
use Pulsar\Mail\Transport\MailgunTransport;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Mail\Transport\MailHttpResponse;
use RuntimeException;

use function base64_encode;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(MailgunTransport::class)]
final class MailgunTransportTest extends TestCase
{
    #[Test]
    public function sendsBasicMessage(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $responseBody = json_encode(['id' => '<msg-001@example.com>'], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com')],
            subject: 'Test Subject',
            textBody: 'Hello',
        );

        $result = $transport->send($message);

        self::assertSame('<msg-001@example.com>', $result);
    }

    #[Test]
    public function nameReturnsMailgun(): void
    {
        $config = new MailgunTransportConfig();
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $transport = new MailgunTransport($config, $httpClient);

        self::assertSame('mailgun', $transport->name());
    }

    #[Test]
    public function sendsMessageWithAllFields(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $responseBody = json_encode(['id' => '<msg-002@example.com>'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.mailgun.net/v3/example.com/messages',
                self::callback(static function (array $headers): bool {
                    return isset($headers['Authorization'])
                        && $headers['Authorization'] === 'Basic ' . base64_encode('api:key-abc123')
                        && $headers['Content-Type'] === 'application/x-www-form-urlencoded';
                }),
                self::callback(static function (string $body): bool {
                    parse_str($body, $params);

                    $to = $params['to'] ?? '';

                    return ($params['from'] ?? '') === 'Sender <sender@example.com>'
                        && is_string($to) && str_contains($to, 'recipient@example.com')
                        && ($params['subject'] ?? '') === 'Test Subject'
                        && ($params['html'] ?? '') === '<p>Hello</p>'
                        && ($params['text'] ?? '') === 'Hello'
                        && isset($params['cc'])
                        && isset($params['bcc'])
                        && ($params['h:Reply-To'] ?? '') === 'reply@example.com'
                        && ($params['h:X-Custom'] ?? '') === 'custom-value'
                        && ($params['v:campaign'] ?? '') === 'promo';
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com', 'Recipient')],
            subject: 'Test Subject',
            cc: [new Address('cc@example.com')],
            bcc: [new Address('bcc@example.com')],
            replyTo: new Address('reply@example.com'),
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
            headers: ['X-Custom' => 'custom-value'],
            metadata: ['campaign' => 'promo'],
        );

        $result = $transport->send($message);

        self::assertSame('<msg-002@example.com>', $result);
    }

    #[Test]
    public function throwsOnHttpError(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(401, 'Unauthorized'));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/mailgun/');
        $transport->send($message);
    }

    #[Test]
    public function wrapsUnexpectedException(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('network failure'));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/network failure/');
        $transport->send($message);
    }

    #[Test]
    public function returnsEmptyStringWhenNoIdInResponse(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $responseBody = json_encode(['message' => 'Queued'], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $result = $transport->send($message);

        self::assertSame('', $result);
    }

    #[Test]
    public function usesCustomEndpoint(): void
    {
        $config = new MailgunTransportConfig(
            domain: 'example.com',
            apiKey: 'key-abc123',
            endpoint: 'https://api.eu.mailgun.net',
        );
        $responseBody = json_encode(['id' => '<eu-msg@example.com>'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.eu.mailgun.net/v3/example.com/messages',
                self::anything(),
                self::anything(),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $transport->send($message);
    }

    #[Test]
    public function buildPayloadHandlesFromWithoutName(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $responseBody = json_encode(['id' => '<msg@example.com>'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (string $body): bool {
                    parse_str($body, $params);

                    return ($params['from'] ?? '') === 'sender@example.com';
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $transport->send($message);
    }

    #[Test]
    public function metadataEncodesNonStringValues(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $responseBody = json_encode(['id' => '<msg@example.com>'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (string $body): bool {
                    parse_str($body, $params);

                    return isset($params['v:data']) && $params['v:data'] === '[1,2,3]';
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
            metadata: ['data' => [1, 2, 3]],
        );

        $transport->send($message);
    }

    #[Test]
    public function rethrowsMailException(): void
    {
        $config = new MailgunTransportConfig(domain: 'example.com', apiKey: 'key-abc123');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(500, 'Server Error'));

        $transport = new MailgunTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        try {
            $transport->send($message);
            self::fail('Expected MailException');
        } catch (MailException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
