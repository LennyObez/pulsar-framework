<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Http\Middleware\CommentAntiAbuseMiddleware;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamPipelineInterface;
use Pulsar\Security\AntiSpam\AntiSpamResult;

#[CoversClass(CommentAntiAbuseMiddleware::class)]
final class CommentAntiAbuseMiddlewareTest extends TestCase
{
    #[Test]
    public function processPassesThroughForNonArrayBody(): void
    {
        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest(method: 'GET', uri: '/comments');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processPassesThroughForMissingBodyField(): void
    {
        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['name' => 'Test']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processPassesThroughForEmptyBody(): void
    {
        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => '']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processPassesThroughWhenPipelinePasses(): void
    {
        $passResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::pass('honeypot'),
            AntiSpamCheckResult::pass('link_density'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($passResult);

        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'Great article, thank you!']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(201, $result->getStatusCode());
    }

    #[Test]
    public function processReturnsFakeSuccessOnHoneypotFailure(): void
    {
        $failResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('honeypot', 50, 'Honeypot field filled'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($failResult);

        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'Spam comment', '_hp_field' => 'bot-value']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        // Honeypot failures return 200 to avoid tipping off bots
        self::assertSame(200, $result->getStatusCode());
        $body = (string) $result->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('success', $decoded['status']);
    }

    #[Test]
    public function processRejects422OnNonHoneypotFailure(): void
    {
        $failResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('link_density', 40, 'Too many links in submission'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($failResult);

        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'https://spam.example.com https://spam2.example.com']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(422, $result->getStatusCode());
        $body = (string) $result->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
    }

    #[Test]
    public function processIncludesReasonFromFailedCheck(): void
    {
        $failResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::pass('honeypot'),
            AntiSpamCheckResult::fail('duplicate', 35, 'Duplicate submission detected'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($failResult);

        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'Exact same comment']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(422, $result->getStatusCode());
        $body = (string) $result->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('Duplicate submission detected', $decoded['message']);
    }
}
