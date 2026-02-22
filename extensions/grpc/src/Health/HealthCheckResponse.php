<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Health;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Response for the grpc.health.v1.Health/Check RPC.
 */
#[Api(since: '1.0.0')]
final readonly class HealthCheckResponse
{
    public function __construct(
        public HealthStatus $status,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            status: HealthStatus::from((int) ($data['status'] ?? HealthStatus::Unknown->value)),
        );
    }

    /**
     * @return array{status: int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
        ];
    }
}
