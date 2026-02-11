<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Exception\MailException;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\Config\SesTransportConfig;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Mail\Transport\MailHttpResponse;
use Pulsar\Mail\Transport\SesTransport;
use RuntimeException;

use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SesTransport::class)]
final class SesTransportTest extends TestCase
{
    #[Test]
    public function sendsBasicMessage(): void
    {
        $config = new SesTransportConfig(region: 'us-east-1', accessKey: 'AKIAIOSFODNN7EXAMPLE');
        $responseBody = json_encode(['MessageId' => 'ses-001'], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new SesTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com')],
            subject: 'Test Subject',
            textBody: 'Hello',
        );

        $result = $transport->send($message);

        self::assertSame('ses-001', $result);
    }

    #[Test]
    public function nameReturnsSes(): void
    {
        $config = new SesTransportConfig();
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $transport = new SesTransport($config, $httpClient);

        self::assertSame('ses', $transport->name());
    }

    #[Test]
    public function sendsMessageWithAllFields(): void
    {
        $config = new SesTransportConfig(region: 'eu-west-1', accessKey: 'AKIAIOSFODNN7EXAMPLE');
        $responseBody = json_encode(['MessageId' => 'ses-002'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails',
                self::callback(static function (array $headers): bool {
                    return ($headers['Content-Type'] ?? '') === 'application/json'
                        && ($headers['X-Amz-Target'] ?? '') === 'SimpleEmailService_v2.SendEmail'
                        && ($headers['X-Pulsar-Ses-Region'] ?? '') === 'eu-west-1'
                        && ($headers['X-Pulsar-Ses-Access-Key'] ?? '') === 'AKIAIOSFODNN7EXAMPLE';
                }),
                self::callback(static function (string $body): bool {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    /** @var array<string, mixed> $content */
                    $content = $payload['Content'] ?? [];
                    /** @var array<string, mixed> $simple */
                    $simple = $content['Simple'] ?? [];
                    /** @var array<string, mixed> $contentBody */
                    $contentBody = $simple['Body'] ?? [];
                    /** @var array<string, mixed> $subject */
                    $subject = $simple['Subject'] ?? [];
                    /** @var array<string, mixed> $destination */
                    $destination = $payload['Destination'] ?? [];

                    $fromEmail = $payload['FromEmailAddress'] ?? '';

                    return isset($contentBody['Html'])
                        && isset($contentBody['Text'])
                        && ($subject['Data'] ?? '') === 'Test Subject'
                        && isset($destination['ToAddresses'])
                        && isset($destination['CcAddresses'])
                        && isset($destination['BccAddresses'])
                        && is_string($fromEmail) && str_contains($fromEmail, 'sender@example.com')
                        && isset($payload['ReplyToAddresses'])
                        && isset($payload['EmailTags']);
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new SesTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com', 'Sender'),
            to: [new Address('recipient@example.com', 'Recipient')],
            subject: 'Test Subject',
            cc: [new Address('cc@example.com')],
            bcc: [new Address('bcc@example.com')],
            replyTo: new Address('reply@example.com'),
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
            metadata: ['campaign' => 'promo'],
        );

        $result = $transport->send($message);

        self::assertSame('ses-002', $result);
    }

    #[Test]
    public function throwsOnHttpError(): void
    {
        $config = new SesTransportConfig(region: 'us-east-1', accessKey: 'AKIAIOSFODNN7EXAMPLE');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(403, 'Forbidden'));

        $transport = new SesTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/ses/');
        $transport->send($message);
    }

    #[Test]
    public function wrapsUnexpectedException(): void
    {
        $config = new SesTransportConfig(region: 'us-east-1', accessKey: 'AKIAIOSFODNN7EXAMPLE');
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('connection reset'));

        $transport = new SesTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/connection reset/');
        $transport->send($message);
    }

    #[Test]
    public function returnsEmptyStringWhenNoMessageIdInResponse(): void
    {
        $config = new SesTransportConfig(region: 'us-east-1', accessKey: 'AKIAIOSFODNN7EXAMPLE');
        $responseBody = json_encode(['RequestId' => 'req-123'], JSON_THROW_ON_ERROR);
        $httpClient = $this->createStub(MailHttpClientInterface::class);
        $httpClient->method('request')->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new SesTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        self::assertSame('', $transport->send($message));
    }

    #[Test]
    public function usesCustomEndpoint(): void
    {
        $config = new SesTransportConfig(
            region: 'us-east-1',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            endpoint: 'https://custom-ses.example.com',
        );
        $responseBody = json_encode(['MessageId' => 'ses-003'], JSON_THROW_ON_ERROR);

        $httpClient = $this->createMock(MailHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://custom-ses.example.com/v2/email/outbound-emails',
                self::anything(),
                self::anything(),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new SesTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
        );

        $transport->send($message);
    }

    #[Test]
    public function fromWithoutNameUsesEmailOnly(): void
    {
        $config = new SesTransportConfig(region: 'us-east-1', accessKey: 'AKIAIOSFODNN7EXAMPLE');
        $responseBody = json_encode(['MessageId' => 'ses-004'], JSON_THROW_ON_ERROR);

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

                    return ($payload['FromEmailAddress'] ?? '') === 'sender@example.com';
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new SesTransport($config, $httpClient);
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
        $config = new SesTransportConfig(region: 'us-east-1', accessKey: 'AKIAIOSFODNN7EXAMPLE');
        $responseBody = json_encode(['MessageId' => 'ses-005'], JSON_THROW_ON_ERROR);

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
                    /** @var list<array<string, mixed>> $tags */
                    $tags = $payload['EmailTags'] ?? [];

                    return isset($tags[0])
                        && ($tags[0]['Name'] ?? '') === 'data'
                        && ($tags[0]['Value'] ?? '') === '{"nested":true}';
                }),
            )
            ->willReturn(new MailHttpResponse(200, $responseBody));

        $transport = new SesTransport($config, $httpClient);
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Test',
            metadata: ['data' => ['nested' => true]],
        );

        $transport->send($message);
    }
}
