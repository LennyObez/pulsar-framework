<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Check\HealthEndpointCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;

#[CoversClass(HealthEndpointCheck::class)]
final class HealthEndpointCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter());

        self::assertSame('health-endpoint', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter());

        self::assertSame('Validates a health check endpoint is registered for monitoring', $check->getDescription());
    }

    #[Test]
    public function it_passes_when_health_endpoint_exists(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter('/health'));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('Health endpoint found', $result->message);
        self::assertStringContainsString('/health', $result->message);
    }

    #[Test]
    public function it_passes_with_healthz_endpoint(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter('/healthz'));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('/healthz', $result->message);
    }

    #[Test]
    public function it_passes_with_health_check_endpoint(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter('/health-check'));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('/health-check', $result->message);
    }

    #[Test]
    public function it_passes_with_health_sub_path(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter('/health/live'));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('/health/live', $result->message);
    }

    #[Test]
    public function it_passes_when_health_endpoint_exists_in_staging(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter('/health'));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_warns_when_no_health_endpoint_in_production(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter());

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('No health check endpoint found', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_no_health_endpoint_in_staging(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter());

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('No health check endpoint found', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_passes_when_no_health_endpoint_in_local(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter());

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not required in local', $result->message);
    }

    #[Test]
    public function production_recommendations_mention_health_route(): void
    {
        $check = new HealthEndpointCheck($this->buildRouter());

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('/health', $joined);
        self::assertStringContainsString('200', $joined);
    }

    #[Test]
    public function it_does_not_match_unrelated_routes(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: self::class,
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: '/dashboard',
            handler: self::class,
        ));

        $check = new HealthEndpointCheck($router);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    private function buildRouter(?string $healthPath = null): Router
    {
        $router = new Router();

        if ($healthPath !== null) {
            $router->add(new Route(
                methods: [Method::GET],
                path: $healthPath,
                handler: self::class,
            ));
        }

        return $router;
    }
}
