<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Routing\Router;

use function sprintf;
use function str_starts_with;

/**
 * Validates that a health check endpoint is registered.
 *
 * Load balancers and orchestration platforms require a health endpoint
 * to monitor application liveness and readiness.
 */
#[Internal]
final readonly class HealthEndpointCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'health-endpoint';

    /** @var list<string> Common health check path prefixes */
    private const array HEALTH_PATHS = ['/health', '/healthz', '/health-check'];

    public function __construct(
        private Router $router,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates a health check endpoint is registered for monitoring';
    }

    public function check(string $environment): CheckResult
    {
        foreach ($this->router->routes as $route) {
            if (array_any(self::HEALTH_PATHS, static fn(string $healthPath): bool => $route->path === $healthPath || str_starts_with($route->path, $healthPath . '/'))) {
                return CheckResult::pass(
                    self::CHECK_NAME,
                    sprintf('Health endpoint found at %s', $route->path),
                );
            }
        }

        return match ($environment) {
            'production' => CheckResult::warning(
                self::CHECK_NAME,
                'No health check endpoint found (/health, /healthz, or /health-check)',
                [
                    'Register a GET /health or /healthz route for load balancer health checks.',
                    'The endpoint should return a 200 status when the application is healthy.',
                    'Consider including database and cache connectivity in the health response.',
                ],
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'No health check endpoint found',
                ['Register a health endpoint to mirror production monitoring setup.'],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Health endpoint not required in local environment',
            ),
        };
    }
}
