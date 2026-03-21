<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $tlsData */
        $tlsData = is_array($data['tls'] ?? null) ? $data['tls'] : [];

        /** @var array<string, mixed> $interceptorData */
        $interceptorData = is_array($data['interceptors'] ?? null) ? $data['interceptors'] : [];

        /** @var array<string, mixed> $rateLimitData */
        $rateLimitData = is_array($data['rate_limit'] ?? null) ? $data['rate_limit'] : [];

        /** @var array<string, mixed> $reflectionData */
        $reflectionData = is_array($data['reflection'] ?? null) ? $data['reflection'] : [];

        /** @var array<string, mixed> $healthData */
        $healthData = is_array($data['health'] ?? null) ? $data['health'] : [];

        /** @var array<string, mixed> $codegenData */
        $codegenData = is_array($data['codegen'] ?? null) ? $data['codegen'] : [];

        /** @var array<string, array<string, mixed>> $identityData */
        $identityData = is_array($data['identity_map'] ?? null) ? $data['identity_map'] : [];

        $identityMap = [];
        foreach ($identityData as $san => $entry) {
            if (is_array($entry)) {
                $identityMap[$san] = IdentityMappingEntry::fromArray($entry);
            }
        }

        return new self(
            host: is_string($data['host'] ?? null) ? $data['host'] : '0.0.0.0',
            port: is_int($data['port'] ?? null) ? $data['port'] : 50051,
            maxWorkers: is_int($data['max_workers'] ?? null) ? $data['max_workers'] : 4,
            maxConcurrentStreams: is_int($data['max_concurrent_streams'] ?? null)
                ? $data['max_concurrent_streams'] : 100,
            keepAliveIntervalSeconds: is_int($data['keep_alive_interval_seconds'] ?? null)
                ? $data['keep_alive_interval_seconds'] : 60,
            keepAliveTimeoutSeconds: is_int($data['keep_alive_timeout_seconds'] ?? null)
                ? $data['keep_alive_timeout_seconds'] : 20,
            adapter: is_string($data['adapter'] ?? null) ? $data['adapter'] : 'grpc_extension',
            tls: TlsConfig::fromArray($tlsData),
            interceptors: InterceptorToggleConfig::fromArray($interceptorData),
            rateLimit: RateLimitConfig::fromArray($rateLimitData),
            reflection: ReflectionConfig::fromArray($reflectionData),
            health: HealthConfig::fromArray($healthData),
            codegen: CodegenConfig::fromArray($codegenData),
            identityMap: $identityMap,
        );
    }
}
