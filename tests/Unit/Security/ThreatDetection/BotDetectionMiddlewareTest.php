<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\ThreatDetection\BotDetectionMiddleware;
use Pulsar\Security\ThreatDetection\BotDetector;
use Pulsar\Security\ThreatDetection\BotScore;

#[CoversClass(BotDetectionMiddleware::class)]
final class BotDetectionMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }

    #[Test]
    public function attachesBotScoreAttribute(): void
    {
        $detector = new BotDetector();
        $middleware = new BotDetectionMiddleware($detector, new NullLogger());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html',
                'Accept-Language' => 'en-US',
                'Accept-Encoding' => 'gzip',
            ],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        // We need to capture the request passed to the handler to check the attribute
        /** @var ServerRequest|null $capturedRequest */
        $capturedRequest = null;
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function (ServerRequest $req) use (&$capturedRequest) {
            $capturedRequest = $req;
            return Response::text('OK');
        });

        $middleware->process($request, $handler);

        if ($capturedRequest === null) {
            self::fail('Expected handler to be called with a request');
        }

        $score = $capturedRequest->getAttribute(BotDetectionMiddleware::BOT_SCORE_ATTR);
        self::assertInstanceOf(BotScore::class, $score);
    }

    #[Test]
    public function allowsLegitimateRequestsThrough(): void
    {
        $detector = new BotDetector();
        $middleware = new BotDetectionMiddleware($detector, new NullLogger(), blockThreshold: 70);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'Connection' => 'keep-alive',
            ],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function blocksBotWhenThresholdExceeded(): void
    {
        $detector = new BotDetector();
        $middleware = new BotDetectionMiddleware($detector, new NullLogger(), blockThreshold: 50);

        // No headers at all - maximum bot score
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $response = $middleware->process($request, $this->handler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function doesNotBlockWhenThresholdIsZero(): void
    {
        $detector = new BotDetector();
        $middleware = new BotDetectionMiddleware($detector, new NullLogger(), blockThreshold: 0);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }
}
