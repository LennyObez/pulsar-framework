<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Comments\AntiAbuseHeuristics;
use Pulsar\Extension\Cms\Http\Middleware\CommentAntiAbuseMiddleware;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\InMemoryTaggedCache;

#[CoversClass(CommentAntiAbuseMiddleware::class)]
final class CommentAntiAbuseMiddlewareTest extends TestCase
{
    private AntiAbuseHeuristics $heuristics;
    private CommentAntiAbuseMiddleware $middleware;

    protected function setUp(): void
    {
        $cache = new InMemoryTaggedCache();
        $this->heuristics = new AntiAbuseHeuristics($cache);
        $this->middleware = new CommentAntiAbuseMiddleware($this->heuristics);
    }

    #[Test]
    public function processPassesThroughForNonArrayBody(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/comments');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $this->middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processPassesThroughForMissingBodyField(): void
    {
        $request = (new ServerRequest(method: 'POST', uri: '/comments'))
            ->withParsedBody(['name' => 'Test']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $this->middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processPassesThroughForEmptyBody(): void
    {
        $request = (new ServerRequest(method: 'POST', uri: '/comments'))
            ->withParsedBody(['body' => '']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $this->middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processRejects422WhenTooLong(): void
    {
        $middleware = new CommentAntiAbuseMiddleware($this->heuristics, maxLength: 10);

        $request = (new ServerRequest(method: 'POST', uri: '/comments'))
            ->withParsedBody(['body' => 'This comment exceeds the max length']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(422, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('maximum length', $body);
    }

    #[Test]
    public function processRejects422ForExcessiveRepetition(): void
    {
        $request = (new ServerRequest(method: 'POST', uri: '/comments'))
            ->withParsedBody(['body' => 'aaaaaaaaaa repeating']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $this->middleware->process($request, $handler);

        self::assertSame(422, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('repetition', $body);
    }

    #[Test]
    public function processRejects422ForLinkSpam(): void
    {
        $middleware = new CommentAntiAbuseMiddleware($this->heuristics, maxLinks: 2);

        $request = (new ServerRequest(method: 'POST', uri: '/comments'))
            ->withParsedBody(['body' => 'Visit https://a.com https://b.com https://c.com']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(422, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('links', $body);
    }

    #[Test]
    public function processPassesThroughForValidComment(): void
    {
        $request = (new ServerRequest(method: 'POST', uri: '/comments'))
            ->withParsedBody(['body' => 'Great article, thank you!']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $handler->method('handle')->willReturn($response);

        $result = $this->middleware->process($request, $handler);

        self::assertSame(201, $result->getStatusCode());
    }

    #[Test]
    public function processRejectsDuplicateSubmission(): void
    {
        $validResponse = $this->createStub(ResponseInterface::class);
        $validResponse->method('getStatusCode')->willReturn(201);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($validResponse);

        $request = (new ServerRequest(method: 'POST', uri: '/comments'))
            ->withParsedBody(['body' => 'Exact same comment']);

        // First submission succeeds
        $result1 = $this->middleware->process($request, $handler);
        self::assertSame(201, $result1->getStatusCode());

        // Second identical submission is rejected as duplicate
        $result2 = $this->middleware->process($request, $handler);
        self::assertSame(422, $result2->getStatusCode());
        $body = (string) $result2->getBody();
        self::assertStringContainsString('Duplicate', $body);
    }
}
