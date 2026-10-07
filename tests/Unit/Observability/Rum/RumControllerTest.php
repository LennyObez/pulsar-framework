<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Rum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Rum\RumCollector;
use Pulsar\Observability\Rum\RumController;

use function json_encode;
use function str_repeat;

/**
 * The RUM endpoint is the one framework route that turns a body from an
 * unauthenticated browser into server state, so the tests here are as much about
 * what it REFUSES as about what it accepts.
 *
 * Before this suite it refused almost nothing: any caller anywhere could POST a
 * body of any size to it, and each distinct `url` field opened a new time series
 * in the in-process metric registry that outlived the request. That is the open
 * beacon — not that the data was wrong, but that a stranger could make the
 * worker keep it.
 */
#[CoversClass(RumController::class)]
final class RumControllerTest extends TestCase
{
    private const string ORIGIN = 'https://app.example.com';

    private RumController $controller;

    protected function setUp(): void
    {
        $this->controller = new RumController(new RumCollector(new MetricRegistry()));
    }

    #[Test]
    public function returns400OnMalformedJsonInsteadOfThrowing(): void
    {
        // Before the fix, JSON_THROW_ON_ERROR let the JsonException escape
        // __invoke() uncaught, contradicting the documented "400 on malformed
        // input" contract and risking leaking internal stack frames.
        $response = ($this->controller)(self::sameOriginRequest('{invalid'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Invalid JSON', (string) $response->getBody());
    }

    #[Test]
    public function returns400OnEmptyBody(): void
    {
        $response = ($this->controller)(self::sameOriginRequest(''));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function returns400WhenJsonIsNotAnObject(): void
    {
        $response = ($this->controller)(self::sameOriginRequest('"a bare string"'));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function acceptsWellFormedPayload(): void
    {
        $response = ($this->controller)(
            self::sameOriginRequest('{"metrics":[{"name":"lcp","value":1250.5,"url":"/home"}]}'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"accepted":1', (string) $response->getBody());
    }

    /**
     * The beacon case: a page on someone else's site posting into this endpoint.
     *
     * The browser stamps that request with its own `Origin`, and the mismatch is
     * the refusal. Without this check the endpoint accepted the batch and the
     * metrics it carried, which is how a third-party page turns a diagnostics
     * endpoint into its own free telemetry sink.
     */
    #[Test]
    public function refusesAPostFromAnotherOrigin(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: self::ORIGIN . '/_pulsar/rum/collect',
            headers: ['Origin' => 'https://evil.example.net'],
            body: '{"metrics":[{"name":"lcp","value":10,"url":"/home"}]}',
        );

        $response = ($this->controller)($request);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * A missing `Origin` is a refusal too. Every browser attaches it to a POST,
     * including `navigator.sendBeacon`, which is how the shipped client reports;
     * a POST arriving without one did not come from the page this endpoint
     * serves, and treating "no evidence" as "same origin" would make the check
     * skippable by omission.
     */
    #[Test]
    public function refusesAPostThatCarriesNoOrigin(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: self::ORIGIN . '/_pulsar/rum/collect',
            body: '{"metrics":[{"name":"lcp","value":10,"url":"/home"}]}',
        );

        $response = ($this->controller)($request);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The scheme is deliberately not compared: a TLS-terminating proxy leaves
     * the worker seeing plain HTTP for a request the browser made over HTTPS,
     * and comparing schemes would refuse every real deployment behind one.
     */
    #[Test]
    public function acceptsTheSameHostBehindATlsTerminatingProxy(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: 'http://app.example.com/_pulsar/rum/collect',
            headers: ['Origin' => 'https://app.example.com'],
            body: '{"metrics":[{"name":"lcp","value":10,"url":"/home"}]}',
        );

        self::assertSame(200, ($this->controller)($request)->getStatusCode());
    }

    /**
     * An oversized body is refused before it is parsed. The cap is what keeps a
     * single request from being an arbitrary allocation in the worker.
     */
    #[Test]
    public function refusesABodyLargerThanTheCap(): void
    {
        $filler = str_repeat('a', RumController::MAX_BODY_BYTES);
        $body = json_encode(['metrics' => [['name' => 'lcp', 'value' => 1, 'url' => '/' . $filler]]]);

        self::assertIsString($body);

        $response = ($this->controller)(self::sameOriginRequest($body));

        self::assertSame(413, $response->getStatusCode());
    }

    private static function sameOriginRequest(string $body): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: self::ORIGIN . '/_pulsar/rum/collect',
            headers: ['Origin' => self::ORIGIN],
            body: $body,
        );
    }
}
