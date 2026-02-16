<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Validation\UrlSafetyValidator;
use Throwable;

use function file_get_contents;
use function microtime;
use function sprintf;
use function stream_context_create;

/**
 * HTTP-based health check for service instances.
 *
 * Performs a GET request to the service's health endpoint and evaluates
 * the HTTP status code. A 2xx response is healthy, 5xx is unhealthy,
 * and other codes indicate degraded status.
 *
 * By default, private/internal IP addresses are allowed since health checks
 * typically target internal services. Set $allowPrivateNetworks to false
 * to enforce SSRF protection for external-facing health checks.
 */
#[Api(since: '1.0.0')]
final readonly class HttpHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private string $healthPath = '/health',
        private float $timeoutSeconds = 5.0,
        private bool $allowPrivateNetworks = true,
    ) {}

    #[Override]
    public function check(ServiceInstance $instance): HealthCheckResult
    {
        $url = sprintf('%s%s', $instance->uri(), $this->healthPath);

        // SSRF protection: validate URL before making the request
        $validation = UrlSafetyValidator::validate($url, $this->allowPrivateNetworks);

        if (!$validation->safe) {
            return HealthCheckResult::unhealthy($validation->reason);
        }

        $start = microtime(true);

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $this->timeoutSeconds,
                    'ignore_errors' => true,
                ],
            ]);

            /** @var list<string> $http_response_header */
            $http_response_header = [];
            $response = @file_get_contents($url, false, $context);
            $latencyMs = (microtime(true) - $start) * 1000.0;

            if ($response === false) {
                return HealthCheckResult::unhealthy('Connection failed');
            }

            // Extract HTTP status from response headers
            $statusCode = $this->extractStatusCode($http_response_header);

            if ($statusCode >= 200 && $statusCode < 300) {
                return HealthCheckResult::healthy($latencyMs);
            }

            if ($statusCode >= 500) {
                return HealthCheckResult::unhealthy(
                    sprintf('HTTP %d', $statusCode),
                );
            }

            return HealthCheckResult::degraded(
                sprintf('HTTP %d', $statusCode),
                $latencyMs,
            );
        } catch (Throwable $e) {
            return HealthCheckResult::unhealthy($e->getMessage());
        }
    }

    /**
     * @param list<string> $headers
     */
    private function extractStatusCode(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        // First header line: "HTTP/1.1 200 OK"
        $parts = explode(' ', $headers[0], 3);

        return isset($parts[1]) ? (int) $parts[1] : 0;
    }
}
