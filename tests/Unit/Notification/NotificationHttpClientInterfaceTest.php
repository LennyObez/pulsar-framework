<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\NotificationHttpClientInterface;

#[CoversClass(NotificationHttpClientInterface::class)]
final class NotificationHttpClientInterfaceTest extends TestCase
{
    #[Test]
    public function implementationReturnsStatusCode(): void
    {
        $client = new class implements NotificationHttpClientInterface {
            public function request(string $method, string $url, array $headers, string $body): int
            {
                return 200;
            }
        };

        $status = $client->request('POST', 'https://hooks.example.com', ['Content-Type' => 'application/json'], '{}');

        self::assertSame(200, $status);
    }

    #[Test]
    #[DataProvider('httpMethodProvider')]
    public function acceptsStandardHttpMethods(string $method): void
    {
        $client = new class implements NotificationHttpClientInterface {
            public string $receivedMethod = '';

            public function request(string $method, string $url, array $headers, string $body): int
            {
                $this->receivedMethod = $method;

                return 200;
            }
        };

        $client->request($method, 'https://example.com', [], '');

        self::assertSame($method, $client->receivedMethod);
    }

    /** @return iterable<string, array{string}> */
    public static function httpMethodProvider(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[Test]
    public function implementationReceivesHeaders(): void
    {
        $client = new class implements NotificationHttpClientInterface {
            /** @var array<string, string> */
            public array $receivedHeaders = [];

            public function request(string $method, string $url, array $headers, string $body): int
            {
                $this->receivedHeaders = $headers;

                return 200;
            }
        };

        $headers = ['Authorization' => 'Bearer token', 'X-Custom' => 'value'];
        $client->request('POST', 'https://example.com', $headers, '');

        self::assertSame('Bearer token', $client->receivedHeaders['Authorization']);
        self::assertSame('value', $client->receivedHeaders['X-Custom']);
    }

    #[Test]
    public function implementationCanReturnErrorCodes(): void
    {
        $client = new class implements NotificationHttpClientInterface {
            public function request(string $method, string $url, array $headers, string $body): int
            {
                return 503;
            }
        };

        self::assertSame(503, $client->request('POST', 'https://example.com', [], ''));
    }
}
