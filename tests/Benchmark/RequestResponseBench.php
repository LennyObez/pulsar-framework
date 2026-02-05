<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class RequestResponseBench
{
    /**
     * Minimal Request construction.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchRequestCreation(): void
    {
        $_ = new Request(
            method: Method::GET,
            uri: '/users/123',
            path: '/users/123',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    /**
     * Request construction with headers, query, and body.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchRequestCreationWithData(): void
    {
        $_ = new Request(
            method: Method::POST,
            uri: '/api/users?page=1',
            path: '/api/users',
            queryString: 'page=1',
            headers: new HeaderBag([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer token123',
            ]),
            body: '{"name":"John","email":"john@example.com"}',
            query: ['page' => '1'],
            post: [],
        );
    }

    /**
     * Plain text response via static factory.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchResponseText(): void
    {
        Response::text('Hello, World!');
    }

    /**
     * HTML response via static factory.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchResponseHtml(): void
    {
        Response::html('<h1>Hello</h1>');
    }

    /**
     * JSON response via static factory.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchResponseJson(): void
    {
        Response::json(['status' => 'ok', 'data' => ['id' => 1, 'name' => 'Test']]);
    }

    /**
     * Direct Response constructor.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchResponseDirectConstruction(): void
    {
        $_ = new Response(body: 'OK');
    }

    /**
     * HeaderBag creation with multiple headers.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchHeaderBagCreation(): void
    {
        new HeaderBag([
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Request-Id' => 'abc-123-def-456',
            'Accept' => ['text/html', 'application/json'],
            'X-Custom-Header' => 'custom-value',
        ]);
    }

    /**
     * Response immutable withHeader chain.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchResponseWithHeaderChain(): void
    {
        new Response(body: 'OK')
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('X-Request-Id', 'bench-123');
    }
}
