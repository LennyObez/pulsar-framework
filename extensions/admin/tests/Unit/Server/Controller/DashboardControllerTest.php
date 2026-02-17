<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;
use Pulsar\Extension\Admin\Server\Controller\DashboardController;

#[CoversClass(DashboardController::class)]
final class DashboardControllerTest extends TestCase
{
    #[Test]
    public function index_returns_json_when_accept_header_requests_json(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        $handler = new DashboardHandler($registry, []);
        $config = AdminConfig::fromArray(['enabled' => true]);

        $controller = new DashboardController($handler, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }
}
