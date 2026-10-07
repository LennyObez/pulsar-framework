<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\RedirectController;
use Pulsar\Extension\Cms\Seo\RedirectManagerInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(RedirectController::class)]
final class RedirectControllerTest extends TestCase
{
    #[Test]
    public function index_returns_paginated_redirects(): void
    {
        $redirect = new Redirect(
            id: 'redir-1',
            fromPath: '/old',
            toPath: '/new',
            statusCode: 301,
            locale: null,
            hits: 42,
            lastHitAt: new DateTimeImmutable('2026-02-01T12:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            createdBy: 'user-1',
            reason: 'Page moved',
            tenantId: null,
        );

        $manager = $this->createStub(RedirectManagerInterface::class);
        $manager->method('listAll')->willReturn([$redirect]);

        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('application/json');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array<string, mixed>> $redirects */
        $redirects = $body['redirects'];
        self::assertCount(1, $redirects);
        self::assertSame('/old', $redirects[0]['from_path']);
        self::assertSame('/new', $redirects[0]['to_path']);
        self::assertSame(42, $redirects[0]['hits']);
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $manager = $this->createStub(RedirectManagerInterface::class);
        $controller = new RedirectController(redirectManager: $manager);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function create_returns_201_for_valid_redirect(): void
    {
        $redirect = new Redirect(
            id: 'redir-new',
            fromPath: '/about-us',
            toPath: '/about',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: new DateTimeImmutable(),
            createdBy: 'admin-1',
            reason: 'Created via admin',
            tenantId: null,
        );

        $manager = $this->createStub(RedirectManagerInterface::class);
        $manager->method('create')->willReturn($redirect);

        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('application/json', parsedBody: [
            'from_path' => '/about-us',
            'to_path' => '/about',
            'status_code' => 301,
        ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('/about-us', $body);
        self::assertStringContainsString('/about', $body);
    }

    #[Test]
    public function create_returns_400_when_from_path_missing(): void
    {
        $manager = $this->createStub(RedirectManagerInterface::class);
        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('application/json', parsedBody: [
            'to_path' => '/new',
        ]);

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_400_for_invalid_status_code(): void
    {
        $manager = $this->createStub(RedirectManagerInterface::class);
        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('application/json', parsedBody: [
            'from_path' => '/old',
            'to_path' => '/new',
            'status_code' => 302,
        ]);

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());

        $responseBody = (string) $response->getBody();
        self::assertStringContainsString('301 or 308', $responseBody);
    }

    #[Test]
    public function create_returns_422_when_manager_throws(): void
    {
        $manager = $this->createStub(RedirectManagerInterface::class);
        $manager->method('create')->willThrowException(CmsException::slugConflict('/old', 'en'));

        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('application/json', parsedBody: [
            'from_path' => '/old',
            'to_path' => '/new',
            'status_code' => 301,
        ]);

        $response = $controller->create($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success(): void
    {
        $manager = $this->createStub(RedirectManagerInterface::class);
        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('application/json');

        $response = $controller->delete($request, 'redir-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
        self::assertSame('redir-1', $body['id']);
    }

    #[Test]
    public function delete_returns_422_when_manager_throws(): void
    {
        $manager = $this->createStub(RedirectManagerInterface::class);
        $manager->method('delete')->willThrowException(CmsException::contentNotFound('nonexistent'));

        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('application/json');

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function export_returns_csv_response(): void
    {
        $redirect = new Redirect(
            id: 'redir-1',
            fromPath: '/old',
            toPath: '/new',
            statusCode: 301,
            locale: 'en',
            hits: 10,
            lastHitAt: new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            createdBy: 'user-1',
            reason: 'Page moved',
            tenantId: null,
        );

        $manager = $this->createStub(RedirectManagerInterface::class);
        $manager->method('listAll')->willReturn([$redirect]);

        $controller = new RedirectController(redirectManager: $manager);
        $request = $this->createAuthenticatedRequest('text/csv');

        $response = $controller->export($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('redirects.csv', $response->getHeaderLine('Content-Disposition'));

        $csv = (string) $response->getBody();
        self::assertStringContainsString('/old', $csv);
        self::assertStringContainsString('/new', $csv);
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        string $accept,
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/seo/redirects');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($accept);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getUploadedFiles')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/seo/redirects');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
