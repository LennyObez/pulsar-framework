<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Reminder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Booking\Reminder\VonageSmsProvider;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;

#[CoversClass(VonageSmsProvider::class)]
final class VonageSmsProviderTest extends TestCase
{
    private HttpClientInterface&Stub $httpClient;
    private VonageSmsProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createStub(HttpClientInterface::class);
        $this->provider = new VonageSmsProvider(
            $this->httpClient,
            'api-key-123',
            'api-secret-456',
            '+15550001234',
        );
    }

    public function testSendSuccessfully(): void
    {
        $responseBody = '{"messages":[{"status":"0","message-id":"MSG123"}]}';
        $response = HttpResponse::fromRaw(200, ['Content-Type' => 'application/json'], $responseBody);
        $this->httpClient->method('post')->willReturn($response);

        $this->provider->send('+15559999999', 'Test message');

        $this->addToAssertionCount(1);
    }

    public function testSendThrowsOnHttpFailure(): void
    {
        $response = HttpResponse::fromRaw(401, [], '{"error":"Unauthorized"}');
        $this->httpClient->method('post')->willReturn($response);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('vonage');

        $this->provider->send('+15559999999', 'Test message');
    }

    public function testSendThrowsOnApiError(): void
    {
        $responseBody = '{"messages":[{"status":"4","error-text":"Invalid credentials"}]}';
        $response = HttpResponse::fromRaw(200, ['Content-Type' => 'application/json'], $responseBody);
        $this->httpClient->method('post')->willReturn($response);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('Invalid credentials');

        $this->provider->send('+15559999999', 'Test message');
    }

    public function testSendSucceedsWithEmptyMessages(): void
    {
        $responseBody = '{"messages":[]}';
        $response = HttpResponse::fromRaw(200, ['Content-Type' => 'application/json'], $responseBody);
        $this->httpClient->method('post')->willReturn($response);

        $this->provider->send('+15559999999', 'Test message');

        $this->addToAssertionCount(1);
    }
}
