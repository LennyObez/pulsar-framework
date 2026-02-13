<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Health;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Standard gRPC health check service per grpc.health.v1.Health.
 *
 * Tracks per-service health status and supports the Check RPC.
 */
#[Api(since: '1.0.0')]
final class HealthService implements ServiceHandlerInterface
{
    private const string SERVICE_NAME = 'grpc.health.v1.Health';

    /** @var array<string, HealthStatus> */
    private array $statuses = ['' => HealthStatus::Serving];

    public function serviceName(): string
    {
        return self::SERVICE_NAME;
    }

    /**
     * @return array<string, MethodDescriptor>
     */
    public function methods(): array
    {
        return [
            'Check' => new MethodDescriptor(
                name: 'Check',
                fullName: '/' . self::SERVICE_NAME . '/Check',
                type: MethodType::Unary,
                inputType: 'grpc.health.v1.HealthCheckRequest',
                outputType: 'grpc.health.v1.HealthCheckResponse',
                handler: self::class . '::handleCheck',
            ),
        ];
    }

    public function invoke(string $method, string $payload): string
    {
        return match ($method) {
            'Check' => $this->handleCheck($payload),
            default => throw new InvalidArgumentException("Unknown method: $method"),
        };
    }

    /**
     * Check the health status of a service.
     */
    public function check(string $service = ''): HealthCheckResponse
    {
        $status = $this->statuses[$service] ?? HealthStatus::ServiceUnknown;

        return new HealthCheckResponse($status);
    }

    /**
     * Set the health status for a specific service.
     */
    public function setStatus(string $service, HealthStatus $status): void
    {
        $this->statuses[$service] = $status;
    }

    /**
     * Set the overall (empty-service) health status.
     */
    public function setOverallStatus(HealthStatus $status): void
    {
        $this->statuses[''] = $status;
    }

    private function handleCheck(string $payload): string
    {
        /** @var array{service?: string} $request */
        $request = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $service = (string) ($request['service'] ?? '');

        $response = $this->check($service);

        return json_encode($response->toArray(), JSON_THROW_ON_ERROR);
    }
}
