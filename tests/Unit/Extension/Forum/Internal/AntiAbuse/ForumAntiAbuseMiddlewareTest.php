<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\AntiAbuse;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Forum\Internal\AntiAbuse\ForumAntiAbuseMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamPipelineInterface;
use Pulsar\Security\AntiSpam\AntiSpamResult;

#[CoversClass(ForumAntiAbuseMiddleware::class)]
final class ForumAntiAbuseMiddlewareTest extends TestCase
{
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn(new Response(200));
    }

    #[Test]
    public function passesRequestThroughWhenBodyIsNotArray(): void
    {
        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $middleware = new ForumAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest('POST', new Uri(path: '/forum/post'))
            ->withParsedBody(null);

        $response = $middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function passesRequestThroughWhenBodyFieldMissing(): void
    {
        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $middleware = new ForumAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest('POST', new Uri(path: '/forum/post'))
            ->withParsedBody(['title' => 'No body field']);

        $response = $middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function passesRequestThroughWhenBodyFieldEmpty(): void
    {
        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $middleware = new ForumAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest('POST', new Uri(path: '/forum/post'))
            ->withParsedBody(['body' => '']);

        $response = $middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function passesRequestThroughWhenPipelinePasses(): void
    {
        $passResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::pass('honeypot'),
            AntiSpamCheckResult::pass('link_density'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($passResult);

        $middleware = new ForumAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest('POST', new Uri(path: '/forum/post'))
            ->withParsedBody(['body' => 'A normal forum post.']);

        $response = $middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsFakeSuccessOnHoneypotFailure(): void
    {
        $failResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('honeypot', 50, 'Honeypot field filled'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($failResult);

        $middleware = new ForumAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest('POST', new Uri(path: '/forum/post'))
            ->withParsedBody(['body' => 'Spam post', '_hp_field' => 'bot-value']);

        $response = $middleware->process($request, $this->handler);

        // Honeypot failures return 200 to avoid tipping off bots
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('success', $decoded['status']);
    }

    #[Test]
    public function returnsUnprocessableEntityOnNonHoneypotFailure(): void
    {
        $failResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('link_density', 40, 'Too many links'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($failResult);

        $middleware = new ForumAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest('POST', new Uri(path: '/forum/post'))
            ->withParsedBody(['body' => 'https://spam.example.com https://spam2.example.com']);

        $response = $middleware->process($request, $this->handler);

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertSame('Unprocessable Entity', $decoded['error']);
    }

    #[Test]
    public function returnsReasonFromFailedCheck(): void
    {
        $failResult = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::pass('honeypot'),
            AntiSpamCheckResult::fail('duplicate', 35, 'Duplicate submission detected'),
        ]);

        $pipeline = $this->createStub(AntiSpamPipelineInterface::class);
        $pipeline->method('evaluate')->willReturn($failResult);

        $middleware = new ForumAntiAbuseMiddleware($pipeline);

        $request = new ServerRequest('POST', new Uri(path: '/forum/post'))
            ->withParsedBody(['body' => 'Duplicate post content']);

        $response = $middleware->process($request, $this->handler);

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('Duplicate submission detected', $decoded['message']);
    }
}
