<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;
use Pulsar\Extension\Admin\Server\Controller\DashboardController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(DashboardController::class)]
final class DashboardControllerTest extends TestCase
{
    /**
     * @param array<string, DataResourceInterface> $registryResources
     * @param list<WidgetInterface> $widgets
     */
    private function makeController(
        array $registryResources = [],
        array $widgets = [],
    ): DashboardController {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn($registryResources);

        $handler = new DashboardHandler($registry, $widgets);
        $config = AdminConfig::fromArray(['enabled' => true]);

        return new DashboardController($handler, $config);
    }

    private function makeJsonRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/admin',
            headers: ['Accept' => 'application/json'],
        );
    }

    #[Test]
    public function indexReturnsJsonResponse(): void
    {
        $widget = $this->createStub(WidgetInterface::class);
        $widget->method('id')->willReturn('resource_count');
        $widget->method('label')->willReturn('Resource Counts');
        $widget->method('size')->willReturn('medium');
        $widget->method('render')->willReturn(['resources' => []]);

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('pluralLabel')->willReturn('Users');
        $resource->method('icon')->willReturn('user');

        $controller = $this->makeController(
            registryResources: ['users' => $resource],
            widgets: [$widget],
        );

        $response = $controller->index($this->makeJsonRequest());

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('widgets', $body);
        self::assertArrayHasKey('resources', $body);
        /** @var list<mixed> $widgets */
        $widgets = $body['widgets'];
        /** @var list<mixed> $resources */
        $resources = $body['resources'];
        self::assertCount(1, $widgets);
        self::assertCount(1, $resources);
    }

    #[Test]
    public function indexJsonResponseContainsWidgetData(): void
    {
        $widget = $this->createStub(WidgetInterface::class);
        $widget->method('id')->willReturn('recent_activity');
        $widget->method('label')->willReturn('Recent Activity');
        $widget->method('size')->willReturn('large');
        $widget->method('render')->willReturn(['entries' => []]);

        $controller = $this->makeController(widgets: [$widget]);

        $response = $controller->index($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        /** @var list<array<string, mixed>> $widgets */
        $widgets = $body['widgets'];
        self::assertSame('recent_activity', $widgets[0]['id']);
        self::assertSame('Recent Activity', $widgets[0]['label']);
    }

    #[Test]
    public function indexJsonResponseWithEmptyDashboard(): void
    {
        $controller = $this->makeController();

        $response = $controller->index($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['widgets']);
        self::assertSame([], $body['resources']);
    }

    #[Test]
    public function indexJsonContainsResourceNames(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('pluralLabel')->willReturn('Orders');
        $resource->method('icon')->willReturn('shopping-cart');

        $controller = $this->makeController(
            registryResources: ['orders' => $resource],
        );

        $response = $controller->index($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        /** @var list<array<string, mixed>> $resources */
        $resources = $body['resources'];
        self::assertSame('orders', $resources[0]['name']);
        self::assertSame('Orders', $resources[0]['label']);
        self::assertSame('shopping-cart', $resources[0]['icon']);
    }
}
