<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Health;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Response for the grpc.health.v1.Health/Check RPC.
 * @api
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
        $rawStatus = is_numeric($data['status'] ?? null) ? (int) $data['status'] : HealthStatus::Unknown->value;

        return new self(
            status: HealthStatus::from($rawStatus),
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
