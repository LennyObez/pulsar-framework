<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\EInvoicing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\EInvoicing\PeppolClient;
use Pulsar\Extension\Payments\EInvoicing\PeppolConfig;
use Pulsar\Extension\Payments\EInvoicing\PeppolTransmissionStatus;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;

use function strlen;

final class PeppolClientTest extends TestCase
{
    private HttpClientInterface&Stub $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createStub(HttpClientInterface::class);
    }

    #[Test]
    public function sendSuccessfulTransmission(): void
    {
        $response = HttpResponse::fromRaw(200, ['Content-Type' => 'application/xml'], '<Receipt/>');
        $this->httpClient->method('post')->willReturn($response);

        $client = new PeppolClient(
            httpClient: $this->httpClient,
            accessPointUrl: 'https://ap.example.com/send',
            senderId: '5412345000013',
            senderScheme: '0088',
        );

        $result = $client->send(
            ublXml: '<Invoice><ID>INV-001</ID></Invoice>',
            receiverId: '9876543210001',
            receiverScheme: '0088',
        );

        self::assertSame(PeppolTransmissionStatus::Accepted, $result->status);
        self::assertNotEmpty($result->transmissionId);
        self::assertSame(32, strlen($result->transmissionId));
        self::assertInstanceOf(DateTimeImmutable::class, $result->timestamp);
        self::assertSame('<Receipt/>', $result->rawResponse);
    }

    #[Test]
    public function sendRejectedTransmission(): void
    {
        $response = HttpResponse::fromRaw(400, ['Content-Type' => 'application/xml'], '<Error>Invalid document</Error>');
        $this->httpClient->method('post')->willReturn($response);

        $client = new PeppolClient(
            httpClient: $this->httpClient,
            accessPointUrl: 'https://ap.example.com/send',
            senderId: '5412345000013',
            senderScheme: '0088',
        );

        $result = $client->send(
            ublXml: '<Invoice/>',
            receiverId: '9876543210001',
            receiverScheme: '0088',
        );

        self::assertSame(PeppolTransmissionStatus::Rejected, $result->status);
        self::assertStringContainsString('Invalid document', $result->rawResponse);
    }

    #[Test]
    public function sendServerErrorResultsInRejection(): void
    {
        $response = HttpResponse::fromRaw(500, [], 'Internal Server Error');
        $this->httpClient->method('post')->willReturn($response);

        $client = new PeppolClient(
            httpClient: $this->httpClient,
            accessPointUrl: 'https://ap.example.com/send',
            senderId: 'sender-1',
            senderScheme: '9925',
        );

        $result = $client->send('<Invoice/>', 'receiver-1', '9925');

        self::assertSame(PeppolTransmissionStatus::Rejected, $result->status);
    }

    #[Test]
    public function sendWrapsXmlInSbdhEnvelope(): void
    {
        $capturedBody = '';
        $response = HttpResponse::fromRaw(200, [], '<ok/>');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->with(
                'https://ap.example.com/send',
                self::callback(static function (array $options) use (&$capturedBody): bool {
                    $capturedBody = $options['body'] ?? '';
                    return true;
                }),
            )
            ->willReturn($response);

        $client = new PeppolClient(
            httpClient: $httpClient,
            accessPointUrl: 'https://ap.example.com/send',
            senderId: '5412345000013',
            senderScheme: '0088',
        );

        (void) $client->send('<Invoice><ID>INV-001</ID></Invoice>', '9876543210001', '0088');

        // Verify SBDH envelope structure
        self::assertIsString($capturedBody);
        self::assertStringContainsString('StandardBusinessDocument', $capturedBody);
        self::assertStringContainsString('StandardBusinessDocumentHeader', $capturedBody);
        self::assertStringContainsString('5412345000013', $capturedBody); // Sender ID
        self::assertStringContainsString('9876543210001', $capturedBody); // Receiver ID
        self::assertStringContainsString('0088', $capturedBody); // Scheme
        self::assertStringContainsString('<Invoice><ID>INV-001</ID></Invoice>', $capturedBody); // Payload
    }

    #[Test]
    public function sendSetsCorrectContentTypeHeader(): void
    {
        $response = HttpResponse::fromRaw(200, [], '<ok/>');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::callback(static function (array $options): bool {
                    /** @var array<string, string> $headers */
                    $headers = $options['headers'] ?? [];
                    return ($headers['Content-Type'] ?? '') === 'application/xml';
                }),
            )
            ->willReturn($response);

        $client = new PeppolClient(
            httpClient: $httpClient,
            accessPointUrl: 'https://ap.example.com/send',
            senderId: 'sender',
            senderScheme: '0088',
        );

        (void) $client->send('<Invoice/>', 'receiver', '0088');
    }

    #[Test]
    public function fromConfigCreatesClientFromConfig(): void
    {
        $config = new PeppolConfig(
            enabled: true,
            accessPointUrl: 'https://peppol.example.com/as4',
            senderId: 'my-sender',
            senderScheme: '9925',
        );

        $client = PeppolClient::fromConfig($config, $this->httpClient);

        // Verify it works by making a send call
        $this->httpClient->method('post')->willReturn(
            HttpResponse::fromRaw(200, [], '<ok/>'),
        );

        $result = $client->send('<Invoice/>', 'receiver', '0088');
        self::assertSame(PeppolTransmissionStatus::Accepted, $result->status);
    }

    #[Test]
    public function sbdhContainsDocumentIdentification(): void
    {
        $capturedBody = '';
        $response = HttpResponse::fromRaw(200, [], '<ok/>');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::callback(static function (array $options) use (&$capturedBody): bool {
                    $capturedBody = $options['body'] ?? '';
                    return true;
                }),
            )
            ->willReturn($response);

        $client = new PeppolClient(
            httpClient: $httpClient,
            accessPointUrl: 'https://ap.example.com/send',
            senderId: 'sender',
            senderScheme: '0088',
        );

        (void) $client->send('<Invoice/>', 'receiver', '0088');

        self::assertIsString($capturedBody);
        self::assertStringContainsString('DocumentIdentification', $capturedBody);
        self::assertStringContainsString('BusinessScope', $capturedBody);
        self::assertStringContainsString('DOCUMENTID', $capturedBody);
        self::assertStringContainsString('PROCESSID', $capturedBody);
    }

    #[Test]
    public function eachSendGeneratesUniqueTransmissionId(): void
    {
        $this->httpClient->method('post')->willReturn(
            HttpResponse::fromRaw(200, [], '<ok/>'),
        );

        $client = new PeppolClient(
            httpClient: $this->httpClient,
            accessPointUrl: 'https://ap.example.com/send',
            senderId: 'sender',
            senderScheme: '0088',
        );

        $result1 = $client->send('<Invoice><ID>1</ID></Invoice>', 'receiver', '0088');
        $result2 = $client->send('<Invoice><ID>2</ID></Invoice>', 'receiver', '0088');

        self::assertNotSame($result1->transmissionId, $result2->transmissionId);
    }
}
