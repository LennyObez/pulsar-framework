<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Server\Controller\TrackerController;
use Pulsar\Http\Message\ServerRequest;

final class TrackerControllerTest extends TestCase
{
    private TrackerController $controller;

    protected function setUp(): void
    {
        $this->controller = new TrackerController();
    }

    #[Test]
    public function scriptReturnsJavascriptContentType(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');

        $response = $this->controller->script($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/javascript', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function scriptReturnsCacheControlHeader(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');

        $response = $this->controller->script($request);

        self::assertStringContainsString('public', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('max-age=86400', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function scriptReturnsEtagHeader(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');

        $response = $this->controller->script($request);

        $etag = $response->getHeaderLine('ETag');
        self::assertNotEmpty($etag);
        self::assertStringStartsWith('"', $etag);
        self::assertStringEndsWith('"', $etag);
    }

    #[Test]
    public function scriptReturnsNoSniffHeader(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');

        $response = $this->controller->script($request);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function scriptReturnsNonEmptyBody(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');

        $response = $this->controller->script($request);

        $body = (string) $response->getBody();
        self::assertNotEmpty($body);
    }

    #[Test]
    public function scriptReturns304WhenEtagMatches(): void
    {
        // First request to get the ETag
        $firstRequest = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');
        $firstResponse = $this->controller->script($firstRequest);
        $etag = $firstResponse->getHeaderLine('ETag');

        // Second request with matching If-None-Match
        $secondRequest = new ServerRequest(
            method: 'GET',
            uri: '/analytics/js/plsr.js',
            headers: ['If-None-Match' => $etag],
        );
        $secondResponse = $this->controller->script($secondRequest);

        self::assertSame(304, $secondResponse->getStatusCode());
        self::assertSame($etag, $secondResponse->getHeaderLine('ETag'));
        self::assertSame('', (string) $secondResponse->getBody());
    }

    #[Test]
    public function scriptReturns200WhenEtagDoesNotMatch(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/js/plsr.js',
            headers: ['If-None-Match' => '"non-matching-etag"'],
        );

        $response = $this->controller->script($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function scriptReturns200WhenIfNoneMatchEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/js/plsr.js',
            headers: ['If-None-Match' => ''],
        );

        $response = $this->controller->script($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function scriptReturnsDeterministicEtag(): void
    {
        $request1 = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');
        $request2 = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');

        $response1 = $this->controller->script($request1);
        $response2 = $this->controller->script($request2);

        self::assertSame(
            $response1->getHeaderLine('ETag'),
            $response2->getHeaderLine('ETag'),
        );
    }

    #[Test]
    public function scriptFallbackContainsTrackerCode(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/js/plsr.js');

        $response = $this->controller->script($request);
        $body = (string) $response->getBody();

        // The fallback script should contain core tracker functionality
        self::assertStringContainsString('plsr', $body);
    }
}
