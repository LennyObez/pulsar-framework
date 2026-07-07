<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pulsar\Http\Factory\RequestFactory;
use Pulsar\Http\Factory\StreamFactory;
use Pulsar\Http\Message\Response;
use Pulsar\Mail\Transport\Psr18MailHttpClient;

#[CoversClass(Psr18MailHttpClient::class)]
final class Psr18MailHttpClientTest extends TestCase
{
    #[Test]
    public function buildsPsr7RequestFromArgumentsAndMapsTheResponse(): void
    {
        $client = new class implements ClientInterface {
            public ?RequestInterface $captured = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return Response::text('queued', 202);
            }
        };

        $adapter = new Psr18MailHttpClient($client, new RequestFactory(), new StreamFactory());

        $response = $adapter->request(
            'POST',
            'https://api.mailgun.net/v3/example.com/messages',
            ['Authorization' => 'Basic abc', 'Content-Type' => 'application/x-www-form-urlencoded'],
            'from=a%40b.com&to=c%40d.com',
        );

        // Response mapped from the PSR-7 response.
        self::assertSame(202, $response->statusCode);
        self::assertSame('queued', $response->body);

        // The PSR-7 request was built faithfully from the call arguments.
        self::assertNotNull($client->captured);
        self::assertSame('POST', $client->captured->getMethod());
        self::assertSame('https://api.mailgun.net/v3/example.com/messages', (string) $client->captured->getUri());
        self::assertSame('Basic abc', $client->captured->getHeaderLine('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $client->captured->getHeaderLine('Content-Type'));
        self::assertSame('from=a%40b.com&to=c%40d.com', (string) $client->captured->getBody());
    }

    #[Test]
    public function surfacesProviderErrorStatusWithoutThrowing(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return Response::text('{"message":"Unauthorized"}', 401);
            }
        };

        $adapter = new Psr18MailHttpClient($client, new RequestFactory(), new StreamFactory());

        $response = $adapter->request('POST', 'https://api.example.com/send', [], '{}');

        // 4xx/5xx are returned (not thrown) so the transport can surface them.
        self::assertSame(401, $response->statusCode);
        self::assertSame('{"message":"Unauthorized"}', $response->body);
    }
}
