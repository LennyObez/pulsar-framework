<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard;
use Pulsar\Observability\Diagnostics\DiagnosticsController;
use Pulsar\Observability\Metrics\MetricRegistry;

#[CoversClass(DiagnosticsController::class)]
final class DiagnosticsControllerTest extends TestCase
{
    #[Test]
    public function returnsUnauthorizedWhenTokenIsNotConfigured(): void
    {
        // Off-by-default: a null token refuses every request.
        $controller = new DiagnosticsController(new DiagnosticsAuthGuard(null), new MetricRegistry());
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->show($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Bearer token', (string) $response->getBody());
        self::assertSame('Bearer realm="pulsar-diagnostics"', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function rendersDiagnosticsWhenAuthorized(): void
    {
        $controller = new DiagnosticsController(new DiagnosticsAuthGuard('secret-token-xyz'), new MetricRegistry());
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer secret-token-xyz');

        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertNotSame('', (string) $response->getBody());
    }
}
