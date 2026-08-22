<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Reminder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Booking\Reminder\TwilioSmsProvider;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;

#[CoversClass(TwilioSmsProvider::class)]
final class TwilioSmsProviderTest extends TestCase
{
    private HttpClientInterface&Stub $httpClient;
    private TwilioSmsProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createStub(HttpClientInterface::class);
        $this->provider = new TwilioSmsProvider(
            $this->httpClient,
            'ACTEST123',
            'auth-token-456',
            '+15550001234',
        );
    }

    public function testSendMakesPostToTwilioApi(): void
    {
        $response = HttpResponse::fromRaw(201, ['Content-Type' => 'application/json'], '{"sid":"SM123"}');
        $this->httpClient->method('post')->willReturn($response);

        $this->provider->send('+15559999999', 'Test message');

        // No exception means success
        $this->addToAssertionCount(1);
    }

    public function testSendThrowsOnHttpFailure(): void
    {
        $response = HttpResponse::fromRaw(401, [], '{"message":"Authentication error"}');
        $this->httpClient->method('post')->willReturn($response);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('twilio');

        $this->provider->send('+15559999999', 'Test message');
    }

    public function testSendThrowsOnServerError(): void
    {
        $response = HttpResponse::fromRaw(500, [], '{"message":"Internal error"}');
        $this->httpClient->method('post')->willReturn($response);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('HTTP 500');

        $this->provider->send('+15559999999', 'Test message');
    }
}
