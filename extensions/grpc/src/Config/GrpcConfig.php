<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Readonly configuration DTO for the gRPC extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GrpcConfig
{
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 50051,
        public int $maxWorkers = 4,
        public int $maxConcurrentStreams = 100,
        public int $keepAliveIntervalSeconds = 60,
        public int $keepAliveTimeoutSeconds = 20,
        public string $adapter = 'grpc_extension',
        public TlsConfig $tls = new TlsConfig(),
        public InterceptorToggleConfig $interceptors = new InterceptorToggleConfig(),
        public RateLimitConfig $rateLimit = new RateLimitConfig(),
        public ReflectionConfig $reflection = new ReflectionConfig(),
        public HealthConfig $health = new HealthConfig(),
        public CodegenConfig $codegen = new CodegenConfig(),
        /** @var array<string, IdentityMappingEntry> */
        public array $identityMap = [],
    ) {}

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     max_workers?: int,
     *     max_concurrent_streams?: int,
     *     keep_alive_interval_seconds?: int,
     *     keep_alive_timeout_seconds?: int,
     *     adapter?: string,
     *     tls?: array<string, mixed>,
     *     interceptors?: array<string, mixed>,
     *     rate_limit?: array<string, mixed>,
     *     reflection?: array<string, mixed>,
     *     health?: array<string, mixed>,
     *     codegen?: array<string, mixed>,
     *     identity_map?: array<string, array<string, mixed>>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $identityMap = [];
        foreach ($data['identity_map'] ?? [] as $san => $entry) {
            $identityMap[$san] = IdentityMappingEntry::fromArray($entry);
        }

        return new self(
            host: $data['host'] ?? '0.0.0.0',
            port: $data['port'] ?? 50051,
            maxWorkers: $data['max_workers'] ?? 4,
            maxConcurrentStreams: $data['max_concurrent_streams'] ?? 100,
            keepAliveIntervalSeconds: $data['keep_alive_interval_seconds'] ?? 60,
            keepAliveTimeoutSeconds: $data['keep_alive_timeout_seconds'] ?? 20,
            adapter: $data['adapter'] ?? 'grpc_extension',
            tls: TlsConfig::fromArray($data['tls'] ?? []),
            interceptors: InterceptorToggleConfig::fromArray($data['interceptors'] ?? []),
            rateLimit: RateLimitConfig::fromArray($data['rate_limit'] ?? []),
            reflection: ReflectionConfig::fromArray($data['reflection'] ?? []),
            health: HealthConfig::fromArray($data['health'] ?? []),
            codegen: CodegenConfig::fromArray($data['codegen'] ?? []),
            identityMap: $identityMap,
        );
    }
}
