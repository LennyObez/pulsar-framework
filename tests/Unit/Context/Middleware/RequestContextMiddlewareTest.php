<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Context\Middleware\RequestContextMiddleware;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
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

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $response = $this->middleware->process($request, $handler);

        self::assertNotNull($capturedContext);
        self::assertSame(32, strlen($capturedContext->correlationId->value));
        self::assertNotSame('', $response->getHeaderLine('X-Correlation-ID'));
    }

    #[Test]
    public function readsValidHexCorrelationHeader(): void
    {
        $correlationId = str_repeat('ab', 16);
        $request = $this->createRequest(['X-Correlation-ID' => [$correlationId]]);
        $capturedContext = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

        self::assertNotNull($capturedContext);
        self::assertSame($correlationId, $capturedContext->correlationId->value);
    }

    #[Test]
    public function rejectsInvalidCorrelationHeaderAndGeneratesNew(): void
    {
        $request = $this->createRequest(['X-Correlation-ID' => ['not-hex-value!!']]);
        $capturedContext = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

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

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

        self::assertNotNull($capturedContext);
        self::assertNotSame($tooLong, $capturedContext->correlationId->value);
    }

    #[Test]
    public function echoesCorrelationAndCausationOnResponse(): void
    {
        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'ok'));

        $response = $this->middleware->process($request, $handler);

        self::assertNotSame('', $response->getHeaderLine('X-Correlation-ID'));
        self::assertNotSame('', $response->getHeaderLine('X-Causation-ID'));
    }

    #[Test]
    public function populatesHolderDuringExecution(): void
    {
        $request = $this->createRequest();
        $holderAvailable = false;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function () use (&$holderAvailable): ResponseInterface {
                $holderAvailable = $this->holder->isAvailable();

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

        self::assertTrue($holderAvailable);
    }

    #[Test]
    public function cleansUpHolderAfterExecution(): void
    {
        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'ok'));

        $this->middleware->process($request, $handler);

        self::assertFalse($this->holder->isAvailable());
    }

    #[Test]
    public function cleansUpHolderOnException(): void
    {
        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('Handler error'));

        try {
            $this->middleware->process($request, $handler);
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

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

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

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

        self::assertNotNull($capturedContext);
        self::assertSame($parentCausation, $capturedContext->attributes['parent_causation_id']);
    }

    #[Test]
    public function rejectsValidHexCorrelationIdWithWrongLength(): void
    {
        // 16 hex chars = valid hex but not 32 chars
        $shortHex = str_repeat('ab', 8);
        $request = $this->createRequest(['X-Correlation-ID' => [$shortHex]]);
        $capturedContext = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

        self::assertNotNull($capturedContext);
        // Should generate a new ID since the header isn't exactly 32 hex chars
        self::assertNotSame($shortHex, $capturedContext->correlationId->value);
        self::assertSame(32, strlen($capturedContext->correlationId->value));
    }

    #[Test]
    public function handlesNonStringRemoteAddr(): void
    {
        $request = $this->createRequest(
            server: ['REMOTE_ADDR' => 12345],
        );
        $capturedContext = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

        self::assertNotNull($capturedContext);
        self::assertNull($capturedContext->ip);
    }

    #[Test]
    public function noCausationHeaderMeansNoParentAttribute(): void
    {
        $request = $this->createRequest();
        $capturedContext = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                /** @var RequestContext $ctx */
                $ctx = $req->getAttribute('_request_context');
                $capturedContext = $ctx;

                return new Response(body: 'ok');
            },
        );

        $this->middleware->process($request, $handler);

        self::assertNotNull($capturedContext);
        self::assertArrayNotHasKey('parent_causation_id', $capturedContext->attributes);
    }

    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $server
     */
    private function createRequest(array $headers = [], array $server = []): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: $headers,
            serverParams: $server,
        );
    }
}
