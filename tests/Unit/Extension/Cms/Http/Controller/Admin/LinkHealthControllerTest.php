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
use Pulsar\Extension\Cms\Http\Controller\Admin\LinkHealthController;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(LinkHealthController::class)]
final class LinkHealthControllerTest extends TestCase
{
    #[Test]
    public function index_returns_broken_links_report(): void
    {
        $check = new LinkHealthCheck(
            id: 'check-1',
            tenantId: null,
            sourceContentId: 'content-1',
            sourceLocale: 'en',
            targetUrl: 'https://example.com/dead-link',
            isBroken: true,
            isRedirected: false,
            httpStatusCode: 404,
            lastCheckedAt: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
        );

        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('getBrokenLinks')->willReturn([$check]);

        $controller = new LinkHealthController(linkHealthService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $links */
        $links = $body['links'];
        self::assertCount(1, $links);
        self::assertSame('check-1', $links[0]['id']);
        self::assertSame('https://example.com/dead-link', $links[0]['target_url']);
        self::assertTrue($links[0]['is_broken']);
        self::assertSame(404, $links[0]['http_status_code']);
    }

    #[Test]
    public function index_returns_empty_list_when_no_broken_links(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('getBrokenLinks')->willReturn([]);

        $controller = new LinkHealthController(linkHealthService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<mixed> $links */
        $links = $body['links'];
        self::assertCount(0, $links);
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $controller = new LinkHealthController(linkHealthService: $service);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new LinkHealthController(linkHealthService: $service, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    #[Test]
    public function run_check_returns_summary_counts(): void
    {
        $now = new DateTimeImmutable();
        $results = [
            new LinkHealthCheck('c1', null, 'cnt-1', 'en', 'https://a.com/ok', false, false, 200, $now, $now),
            new LinkHealthCheck('c2', null, 'cnt-1', 'en', 'https://b.com/dead', true, false, 404, $now, $now),
            new LinkHealthCheck('c3', null, 'cnt-2', 'en', 'https://c.com/moved', false, true, 301, $now, $now),
            new LinkHealthCheck('c4', null, 'cnt-3', 'en', 'https://d.com/gone', true, false, null, $now, $now),
        ];

        $service = $this->createStub(LinkHealthServiceInterface::class);
        $service->method('checkAll')->willReturn($results);

        $controller = new LinkHealthController(linkHealthService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->runCheck($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(4, $body['total_checked']);
        self::assertSame(2, $body['broken']);
        self::assertSame(1, $body['redirected']);
        self::assertSame(1, $body['healthy']);
    }

    #[Test]
    public function run_check_throws_when_unauthenticated(): void
    {
        $service = $this->createStub(LinkHealthServiceInterface::class);
        $controller = new LinkHealthController(linkHealthService: $service);

        $this->expectException(AuthenticationException::class);
        $controller->runCheck($this->createUnauthenticatedRequest());
    }

    private function createAuthenticatedRequest(): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/seo/link-health');

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
        $uri->method('getPath')->willReturn('/admin/seo/link-health');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
