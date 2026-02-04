<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\Middleware\RequestContextMiddleware;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;

use function strlen;

#[CoversClass(RequestContextMiddleware::class)]
final class RequestContextMiddlewareTest extends TestCase
{
    private RequestContextHolder $holder;
    private Randomizer $randomizer;
    private RequestContextMiddleware $middleware;

    protected function setUp(): void
    {
        $this->holder = new RequestContextHolder();
        $this->randomizer = new Randomizer(new Xoshiro256StarStar(42));
        $this->middleware = new RequestContextMiddleware($this->holder, $this->randomizer);
    }

    #[Test]
    public function generatesCorrelationIdWhenNoHeader(): void
    {
        $request = $this->createRequest();
        $capturedContext = null;

        $response = $this->middleware->process($request, function (Request $req) use (&$capturedContext): Response {
            /** @var RequestContext $ctx */
            $ctx = $req->attribute('_request_context');
            $capturedContext = $ctx;

            return new Response(body: 'ok');
        });

        self::assertNotNull($capturedContext);
        self::assertSame(32, strlen($capturedContext->correlationId->value));
        self::assertNotEmpty($response->headers->first('X-Correlation-ID'));
    }

    #[Test]
    public function readsValidHexCorrelationHeader(): void
    {
        $correlationId = str_repeat('ab', 16);
        $request = $this->createRequest(['X-Correlation-ID' => [$correlationId]]);
        $capturedContext = null;

        $this->middleware->process($request, function (Request $req) use (&$capturedContext): Response {
            /** @var RequestContext $ctx */
            $ctx = $req->attribute('_request_context');
            $capturedContext = $ctx;

            return new Response(body: 'ok');
        });

        self::assertNotNull($capturedContext);
        self::assertSame($correlationId, $capturedContext->correlationId->value);
    }

    #[Test]
    public function rejectsInvalidCorrelationHeaderAndGeneratesNew(): void
    {
        $request = $this->createRequest(['X-Correlation-ID' => ['not-hex-value!!']]);
        $capturedContext = null;

        $this->middleware->process($request, function (Request $req) use (&$capturedContext): Response {
            /** @var RequestContext $ctx */
            $ctx = $req->attribute('_request_context');
            $capturedContext = $ctx;

            return new Response(body: 'ok');
        });

        self::assertNotNull($capturedContext);
        self::assertNotSame('not-hex-value!!', $capturedContext->correlationId->value);
        self::assertSame(32, strlen($capturedContext->correlationId->value));
    }

    #[Test]
    public function rejectsOverlongCorrelationHeader(): void
    {
        $tooLong = str_repeat('ab', 40); // 80 chars > 64 max
        $request = $this->createRequest(['X-Correlation-ID' => [$tooLong]]);
        $capturedContext = null;

        $this->middleware->process($request, function (Request $req) use (&$capturedContext): Response {
            /** @var RequestContext $ctx */
            $ctx = $req->attribute('_request_context');
            $capturedContext = $ctx;

            return new Response(body: 'ok');
        });

        self::assertNotNull($capturedContext);
        self::assertNotSame($tooLong, $capturedContext->correlationId->value);
    }

    #[Test]
    public function echoesCorrelationAndCausationOnResponse(): void
    {
        $request = $this->createRequest();

        $response = $this->middleware->process($request, static fn(): Response => new Response(body: 'ok'));

        self::assertNotNull($response->headers->first('X-Correlation-ID'));
        self::assertNotNull($response->headers->first('X-Causation-ID'));
    }

    #[Test]
    public function populatesHolderDuringExecution(): void
    {
        $request = $this->createRequest();
        $holderAvailable = false;

        $this->middleware->process($request, function () use (&$holderAvailable): Response {
            $holderAvailable = $this->holder->isAvailable();

            return new Response(body: 'ok');
        });

        self::assertTrue($holderAvailable);
    }

    #[Test]
    public function cleansUpHolderAfterExecution(): void
    {
        $request = $this->createRequest();

        $this->middleware->process($request, static fn(): Response => new Response(body: 'ok'));

        self::assertFalse($this->holder->isAvailable());
    }

    #[Test]
    public function cleansUpHolderOnException(): void
    {
        $request = $this->createRequest();

        try {
            $this->middleware->process($request, static function (): never {
                throw new RuntimeException('Handler error');
            });
        } catch (RuntimeException) {
            // Expected
        }

        self::assertFalse($this->holder->isAvailable());
    }

    #[Test]
    public function readsIpAndUserAgent(): void
    {
        $request = $this->createRequest(
            headers: ['User-Agent' => ['TestBrowser/1.0']],
            server: ['REMOTE_ADDR' => '192.168.1.100'],
        );
        $capturedContext = null;

        $this->middleware->process($request, function (Request $req) use (&$capturedContext): Response {
            /** @var RequestContext $ctx */
            $ctx = $req->attribute('_request_context');
            $capturedContext = $ctx;

            return new Response(body: 'ok');
        });

        self::assertNotNull($capturedContext);
        self::assertSame('192.168.1.100', $capturedContext->ip);
        self::assertSame('TestBrowser/1.0', $capturedContext->userAgent);
    }

    #[Test]
    public function storesParentCausationIdInAttributes(): void
    {
        $parentCausation = str_repeat('cc', 16);
        $request = $this->createRequest(['X-Causation-ID' => [$parentCausation]]);
        $capturedContext = null;

        $this->middleware->process($request, function (Request $req) use (&$capturedContext): Response {
            /** @var RequestContext $ctx */
            $ctx = $req->attribute('_request_context');
            $capturedContext = $ctx;

            return new Response(body: 'ok');
        });

        self::assertNotNull($capturedContext);
        self::assertSame($parentCausation, $capturedContext->attributes['parent_causation_id']);
    }

    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $server
     */
    private function createRequest(array $headers = [], array $server = []): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag($headers),
            body: '',
            server: $server,
        );
    }
}
