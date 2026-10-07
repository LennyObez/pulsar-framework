<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\RateLimitDashboardController;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(RateLimitDashboardController::class)]
final class RateLimitDashboardControllerTest extends TestCase
{
    #[Test]
    public function dashboard_returns_endpoint_metrics_without_metric_registry(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(null);

        $controller = new RateLimitDashboardController(settings: $settings);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->dashboard($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $endpoints */
        $endpoints = $body['endpoints'];
        self::assertCount(7, $endpoints);

        /** @var array<string, mixed> $summary */
        $summary = $body['summary'];
        self::assertSame(0, $summary['total_requests']);
        self::assertSame(0, $summary['total_rejections']);
        self::assertEquals(0.0, $summary['rejection_rate']);
    }

    #[Test]
    public function dashboard_uses_stored_settings_over_defaults(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn(string $group, string $key): mixed => match ($key) {
                'endpoint:/api/cms/comments:limit' => 100,
                'endpoint:/api/cms/comments:window' => 120,
                default => null,
            },
        );

        $controller = new RateLimitDashboardController(settings: $settings);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->dashboard($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $endpoints */
        $endpoints = $body['endpoints'];

        $commentsEndpoint = null;

        foreach ($endpoints as $ep) {
            if ($ep['endpoint'] === '/api/cms/comments') {
                $commentsEndpoint = $ep;

                break;
            }
        }

        self::assertNotNull($commentsEndpoint);
        self::assertSame(100, $commentsEndpoint['limit']);
        self::assertSame(120, $commentsEndpoint['window']);
    }

    #[Test]
    public function dashboard_collects_metrics_from_registry(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(null);

        $registry = new MetricRegistry();
        $requestsCounter = $registry->counter('rate_limit_requests_total');
        $rejectionsCounter = $registry->counter('rate_limit_rejections_total');

        $labels = new LabelSet(['endpoint' => '/api/cms/comments']);
        $requestsCounter->increment($labels, 100.0);
        $rejectionsCounter->increment($labels, 10.0);

        $controller = new RateLimitDashboardController(
            settings: $settings,
            metricRegistry: $registry,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->dashboard($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $summary */
        $summary = $body['summary'];
        self::assertSame(100, $summary['total_requests']);
        self::assertSame(10, $summary['total_rejections']);
        self::assertEquals(10.0, $summary['rejection_rate']);
    }

    #[Test]
    public function dashboard_collects_top_ips(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(null);

        $registry = new MetricRegistry();
        $ipCounter = $registry->counter('rate_limit_rejections_by_ip');
        $ipCounter->increment(new LabelSet(['ip' => 'hash_a']), 50.0);
        $ipCounter->increment(new LabelSet(['ip' => 'hash_b']), 30.0);

        $controller = new RateLimitDashboardController(
            settings: $settings,
            metricRegistry: $registry,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->dashboard($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $topIps */
        $topIps = $body['topIps'];
        self::assertCount(2, $topIps);
        self::assertSame(50, $topIps[0]['rejections']);
    }

    #[Test]
    public function dashboard_returns_empty_top_ips_without_registry(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(null);

        $controller = new RateLimitDashboardController(settings: $settings);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->dashboard($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<mixed> $topIps */
        $topIps = $body['topIps'];
        self::assertCount(0, $topIps);
    }

    #[Test]
    public function dashboard_enforces_minimum_one_for_stored_settings(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn(string $group, string $key): mixed => match ($key) {
                'endpoint:/api/cms/comments:limit' => -5,
                'endpoint:/api/cms/comments:window' => 0,
                default => null,
            },
        );

        $controller = new RateLimitDashboardController(settings: $settings);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->dashboard($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $endpoints */
        $endpoints = $body['endpoints'];

        $commentsEndpoint = null;

        foreach ($endpoints as $ep) {
            if ($ep['endpoint'] === '/api/cms/comments') {
                $commentsEndpoint = $ep;

                break;
            }
        }

        self::assertNotNull($commentsEndpoint);
        self::assertSame(1, $commentsEndpoint['limit']);
        self::assertSame(1, $commentsEndpoint['window']);
    }

    #[Test]
    public function update_limit_stores_valid_configuration(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new RateLimitDashboardController(settings: $settings);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'endpoint' => '/api/cms/comments',
            'limit' => 50,
            'window' => 120,
        ]);

        $response = $controller->updateLimit($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/api/cms/comments', $body['endpoint']);
        self::assertSame(50, $body['limit']);
        self::assertSame(120, $body['window']);
    }

    #[Test]
    public function update_limit_returns_400_for_empty_endpoint(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new RateLimitDashboardController(settings: $settings);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'endpoint' => '',
            'limit' => 50,
            'window' => 60,
        ]);

        $response = $controller->updateLimit($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Endpoint', (string) $body['error']);
    }

    #[Test]
    public function update_limit_returns_400_for_limit_out_of_range(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new RateLimitDashboardController(settings: $settings);

        // Limit too low
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'endpoint' => '/api/test',
            'limit' => 0,
            'window' => 60,
        ]);

        $response = $controller->updateLimit($request);
        self::assertSame(400, $response->getStatusCode());

        // Limit too high
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'endpoint' => '/api/test',
            'limit' => 10001,
            'window' => 60,
        ]);

        $response = $controller->updateLimit($request);
        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('10,000', (string) $body['error']);
    }

    #[Test]
    public function update_limit_returns_400_for_window_out_of_range(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new RateLimitDashboardController(settings: $settings);

        // Window too low
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'endpoint' => '/api/test',
            'limit' => 10,
            'window' => 0,
        ]);

        $response = $controller->updateLimit($request);
        self::assertSame(400, $response->getStatusCode());

        // Window too high
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'endpoint' => '/api/test',
            'limit' => 10,
            'window' => 86401,
        ]);

        $response = $controller->updateLimit($request);
        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('86,400', (string) $body['error']);
    }

    #[Test]
    public function dashboard_throws_when_unauthenticated(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new RateLimitDashboardController(settings: $settings);

        $this->expectException(AuthenticationException::class);
        $controller->dashboard($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function dashboard_throws_when_authorization_denied(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new RateLimitDashboardController(settings: $settings, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->dashboard($request);
    }

    #[Test]
    public function update_limit_throws_when_unauthenticated(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new RateLimitDashboardController(settings: $settings);

        $this->expectException(AuthenticationException::class);
        $controller->updateLimit($this->createUnauthenticatedRequest());
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(?array $parsedBody = null): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/rate-limits');

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

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/rate-limits');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
