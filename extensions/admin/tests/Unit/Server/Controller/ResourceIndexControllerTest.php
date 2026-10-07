<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Server\Controller\ResourceIndexController;

#[CoversClass(ResourceIndexController::class)]
final class ResourceIndexControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsJsonWhenAcceptJson(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('label')->willReturn('User');
        $resource->method('pluralLabel')->willReturn('Users');
        $resource->method('icon')->willReturn('users');
        $resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn(['users' => $resource]);

        $config = AdminConfig::fromArray(['enabled' => true]);
        $controller = new ResourceIndexController($registry, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function indexReturnsJsonWithEmptyRegistry(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        $config = AdminConfig::fromArray(['enabled' => true]);
        $controller = new ResourceIndexController($registry, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
