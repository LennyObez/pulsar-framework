<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Controller\Admin\MenuController;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(MenuController::class)]
final class MenuControllerTest extends TestCase
{
    #[Test]
    public function index_returns_menus_list(): void
    {
        $repo = $this->createStub(MenuRepositoryInterface::class);
        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<mixed> $menus */
        $menus = $body['menus'];
        self::assertIsArray($menus);
    }

    #[Test]
    public function create_returns_201(): void
    {
        $repo = $this->createStub(MenuRepositoryInterface::class);
        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('created', $body['status']);
    }

    #[Test]
    public function show_returns_menu_by_location(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $menu = new Menu(id: 'menu-1', tenantId: null, location: 'primary', createdAt: $now);

        $repo = $this->createStub(MenuRepositoryInterface::class);
        $repo->method('findByLocation')->willReturn($menu);

        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'primary');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $menuData */
        $menuData = $body['menu'];
        self::assertSame('menu-1', $menuData['id']);
        self::assertSame('primary', $menuData['location']);
    }

    #[Test]
    public function show_returns_404_when_menu_not_found(): void
    {
        $repo = $this->createStub(MenuRepositoryInterface::class);
        $repo->method('findByLocation')->willReturn(null);

        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_success_for_existing_menu(): void
    {
        $now = new DateTimeImmutable();
        $menu = new Menu(id: 'menu-1', tenantId: null, location: 'primary', createdAt: $now);

        $repo = $this->createStub(MenuRepositoryInterface::class);
        $repo->method('findByLocation')->willReturn($menu);

        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'primary');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_404_when_menu_not_found(): void
    {
        $repo = $this->createStub(MenuRepositoryInterface::class);
        $repo->method('findByLocation')->willReturn(null);

        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success_for_existing_menu(): void
    {
        $now = new DateTimeImmutable();
        $menu = new Menu(id: 'menu-1', tenantId: null, location: 'footer', createdAt: $now);

        $repo = $this->createStub(MenuRepositoryInterface::class);
        $repo->method('findByLocation')->willReturn($menu);

        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'footer');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_404_when_menu_not_found(): void
    {
        $repo = $this->createStub(MenuRepositoryInterface::class);
        $repo->method('findByLocation')->willReturn(null);

        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(MenuRepositoryInterface::class);
        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig());

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(MenuRepositoryInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new MenuController(menuRepository: $repo, config: new CmsConfig(), gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createAuthenticatedRequest(): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/menus');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/menus');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
