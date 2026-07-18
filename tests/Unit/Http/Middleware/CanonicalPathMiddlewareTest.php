<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\Middleware\CanonicalPathMiddleware;

#[CoversClass(CanonicalPathMiddleware::class)]
final class CanonicalPathMiddlewareTest extends TestCase
{
    /**
     * A request handler that records whether it ran, standing in for everything
     * downstream (the router and every inner middleware, including the
     * locale-prefix strip). A short-circuiting redirect must leave it untouched.
     *
     * @return RequestHandlerInterface&object{called: bool}
     */
    private function spyHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return Response::text('OK');
            }
        };
    }

    private function request(string $path, string $query = '', string $method = 'GET'): ServerRequest
    {
        return new ServerRequest(method: $method, uri: new Uri(path: $path, query: $query));
    }

    #[Test]
    public function trailingSlashRedirectsToTheTrimmedPath(): void
    {
        $response = new CanonicalPathMiddleware()->process($this->request('/coaching/'), $this->spyHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/coaching', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function leadingDoubleSlashCollapsesToOne(): void
    {
        $response = new CanonicalPathMiddleware()->process($this->request('//coaching'), $this->spyHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/coaching', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function repeatedSlashesAtBothEndsAreCollapsedAndTrimmed(): void
    {
        $response = new CanonicalPathMiddleware()->process($this->request('///coaching///'), $this->spyHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/coaching', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function internalRepeatedSlashesAreCollapsed(): void
    {
        $response = new CanonicalPathMiddleware()->process($this->request('/a//b///c'), $this->spyHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/a/b/c', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function rootPathIsExemptAndPassesThrough(): void
    {
        $handler = $this->spyHandler();
        $response = new CanonicalPathMiddleware()->process($this->request('/'), $handler);

        self::assertTrue($handler->called, 'The root path is canonical and must reach the handler');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function anAlreadyCanonicalPathPassesThrough(): void
    {
        $handler = $this->spyHandler();
        $response = new CanonicalPathMiddleware()->process($this->request('/coaching/sessions'), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function theQueryStringIsPreservedAcrossTheRedirect(): void
    {
        $response = new CanonicalPathMiddleware()->process($this->request('/coaching/', 'ref=news&page=2'), $this->spyHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/coaching?ref=news&page=2', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function aDoubleSlashHostSpellingCannotBecomeAnOpenRedirect(): void
    {
        // "//evil.com" is the classic open-redirect: a protocol-relative URL
        // the browser resolves as a new authority. Collapsing every slash run
        // to one turns it into the same-origin path "/evil.com" (a single
        // leading slash), never a protocol-relative target.
        $response = new CanonicalPathMiddleware()->process($this->request('//evil.com'), $this->spyHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/evil.com', $response->getHeaderLine('Location'));
        self::assertStringStartsNotWith('//', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function headRequestsAlsoRedirectWith301(): void
    {
        $response = new CanonicalPathMiddleware()->process($this->request('/coaching/', method: 'HEAD'), $this->spyHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/coaching', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function nonSafeMethodsRedirectWith308ToPreserveTheBody(): void
    {
        // A 301/302 lets clients rewrite POST to GET and drop the body; 308
        // keeps the method and body, so the non-canonical POST is not lost.
        $response = new CanonicalPathMiddleware()->process($this->request('/checkout/', method: 'POST'), $this->spyHandler());

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/checkout', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function aLocalePrefixedDoubleSlashKeepsThePrefixAndDoesNotReachDownstream(): void
    {
        // Piped OUTERMOST (before LocalePrefixMiddleware), the middleware
        // intercepts `/nl//coaching` and redirects to the canonical
        // `/nl/coaching` — the locale prefix is preserved and the downstream
        // stack (which would otherwise strip `/nl` and see a stray `//coaching`)
        // never runs.
        $handler = $this->spyHandler();
        $response = new CanonicalPathMiddleware()->process($this->request('/nl//coaching'), $handler);

        self::assertFalse($handler->called, 'The redirect must short-circuit before the locale-prefix strip');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/nl/coaching', $response->getHeaderLine('Location'));
    }
}
