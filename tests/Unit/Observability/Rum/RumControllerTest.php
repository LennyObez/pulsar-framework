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

#[CoversClass(RumController::class)]
final class RumControllerTest extends TestCase
{
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
        $request = new ServerRequest(
            method: 'POST',
            uri: '/_pulsar/rum/collect',
            body: '{invalid',
        );

        $response = ($this->controller)($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Invalid JSON', (string) $response->getBody());
    }

    #[Test]
    public function returns400OnEmptyBody(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/_pulsar/rum/collect',
            body: '',
        );

        $response = ($this->controller)($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function returns400WhenJsonIsNotAnObject(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/_pulsar/rum/collect',
            body: '"a bare string"',
        );

        $response = ($this->controller)($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function acceptsWellFormedPayload(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/_pulsar/rum/collect',
            body: '{"metrics":[{"name":"lcp","value":1250.5,"url":"/home"}]}',
        );

        $response = ($this->controller)($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"accepted":1', (string) $response->getBody());
    }
}
