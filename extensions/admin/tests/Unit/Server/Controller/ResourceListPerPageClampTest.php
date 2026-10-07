<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Config\AdminPaginationConfig;
use Pulsar\Extension\Admin\Config\AdminRateLimitConfig;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Config\AdminSecurityConfig;
use Pulsar\Extension\Admin\Config\AdminStorageConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Extension\Admin\Server\Controller\ResourceListController;

#[CoversClass(ResourceListController::class)]
final class ResourceListPerPageClampTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function perPageClampProvider(): iterable
    {
        yield 'over max clamped to max' => ['10000', 100];
        yield 'negative clamped to 1' => ['-5', 1];
        yield 'zero clamped to 1' => ['0', 1];
        yield 'within range passes through' => ['50', 50];
        yield 'exact max passes through' => ['100', 100];
        yield 'non-numeric defaults to 25' => ['abc', 25];
    }

    #[Test]
    #[DataProvider('perPageClampProvider')]
    public function perPageIsClampedToMax(string $rawPerPage, int $expectedPerPage): void
    {
        $capturedPerPage = null;

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('pluralLabel')->willReturn('Users');
        $resource->method('fields')->willReturn([]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturnCallback(
            function ($res, $filters, $sort, $page, $perPage) use (&$capturedPerPage): array {
                $capturedPerPage = $perPage;

                return ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage];
            },
        );

        $config = new AdminConfig(
            enabled: true,
            routePrefix: '/admin',
            security: AdminSecurityConfig::fromArray([]),
            pagination: new AdminPaginationConfig(defaultPerPage: 25, maxPerPage: 100),
            rateLimit: AdminRateLimitConfig::fromArray([]),
            storage: AdminStorageConfig::fromArray([]),
            schema: AdminSchemaConfig::fromArray([]),
        );

        $visibilityFilter = new FieldVisibilityFilter();

        $handler = new ListResourceHandler($registry, $query, $visibilityFilter, $config);
        $controller = new ResourceListController($handler, $registry, $config);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/users');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['per_page' => $rawPerPage]);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);

        $controller->list($request, 'users');

        self::assertSame($expectedPerPage, $capturedPerPage);
    }
}
