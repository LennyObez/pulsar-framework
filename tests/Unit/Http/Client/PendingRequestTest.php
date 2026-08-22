<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Http\Client\PendingRequest;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\ResponseStatus;

use function is_string;

#[CoversClass(PendingRequest::class)]
final class PendingRequestTest extends TestCase
{
    private HttpResponse $stubResponse;

    protected function setUp(): void
    {
        $this->stubResponse = new HttpResponse(ResponseStatus::OK, new HeaderBag(), '');
    }

    #[Test]
    public function withHeadersSetsHeaders(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            self::assertSame('custom-value', $headers['X-Custom']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->withHeaders(['X-Custom' => 'custom-value'])->get('/test');
    }

    #[Test]
    public function withTokenSetsAuthorizationBearerHeader(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('post')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            self::assertSame('Bearer my-token-123', $headers['Authorization']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->withToken('my-token-123')->post('/api');
    }

    #[Test]
    public function withBasicAuthSetsAuthorizationBasicHeader(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            $expected = 'Basic ' . base64_encode('user:pass');
            self::assertSame($expected, $headers['Authorization']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->withBasicAuth('user', 'pass')->get('/protected');
    }

    #[Test]
    public function withBodySetsRawBody(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('put')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            self::assertSame('raw body data', $options['body']);
            self::assertSame('text/plain', $headers['Content-Type']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->withBody('raw body data', 'text/plain')->put('/upload');
    }

    #[Test]
    public function asJsonEncodesBodyAndSetsContentType(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('post')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            $body = is_string($options['body']) ? $options['body'] : '';
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['name' => 'Pulsar'], $decoded);
            self::assertSame('application/json', $headers['Content-Type']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->asJson(['name' => 'Pulsar'])->post('/api/items');
    }

    #[Test]
    public function asFormEncodesBodyAndSetsContentType(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('post')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            self::assertSame('username=admin&password=secret', $options['body']);
            self::assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->asForm(['username' => 'admin', 'password' => 'secret'])->post('/login');
    }

    #[Test]
    public function timeoutSetsTimeoutOption(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            self::assertSame(5.0, $options['timeout']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->timeout(5.0)->get('/slow');
    }

    #[Test]
    public function retrySetsRetryOptions(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            self::assertSame(3, $options['retries']);
            self::assertSame(0.5, $options['retry_delay']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->retry(3, 0.5)->get('/flaky');
    }

    #[Test]
    public function withQuerySetsQueryOption(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            self::assertSame(['page' => '1', 'limit' => '10'], $options['query']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->withQuery(['page' => '1', 'limit' => '10'])->get('/items');
    }

    #[Test]
    public function acceptJsonSetsAcceptHeader(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            self::assertSame('application/json', $headers['Accept']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->acceptJson()->get('/api');
    }

    #[Test]
    public function withUserAgentSetsHeader(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            self::assertSame('Pulsar/1.0', $headers['User-Agent']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request->withUserAgent('Pulsar/1.0')->get('/');
    }

    #[Test]
    public function allHttpMethodsDelegateToClient(): void
    {
        $methods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

        foreach ($methods as $method) {
            $client = $this->createStub(HttpClientInterface::class);
            $client->method($method)->willReturn($this->stubResponse);

            $request = new PendingRequest($client);
            $response = $request->$method('/test');

            self::assertSame($this->stubResponse, $response, "Method {$method} should delegate to client");
        }
    }

    #[Test]
    public function builderIsImmutable(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'] ?? [];
            // The second get() should NOT have the token header
            self::assertArrayNotHasKey('Authorization', $headers);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $withToken = $request->withToken('secret');

        // withToken returns a new instance; the original should be unaffected
        self::assertNotSame($request, $withToken);

        $request->get('/public');
    }

    #[Test]
    public function chainingMultipleOptionsCombinesAll(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('post')->willReturnCallback(function (string $url, array $options): HttpResponse {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            self::assertStringStartsWith('Bearer ', $headers['Authorization']);
            self::assertSame('application/json', $headers['Accept']);
            self::assertSame(10.0, $options['timeout']);
            self::assertSame(2, $options['retries']);
            return $this->stubResponse;
        });

        $request = new PendingRequest($client);
        $request
            ->withToken('abc')
            ->acceptJson()
            ->timeout(10.0)
            ->retry(2)
            ->post('/api/data');
    }
}
