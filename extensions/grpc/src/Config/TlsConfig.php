<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_string;

/**
 * TLS and mTLS configuration for the gRPC server.
 */
#[Api(since: '1.0.0')]
final readonly class TlsConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $certPath = '',
        public string $keyPath = '',
        public string $caPath = '',
        public bool $mutual = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : false,
            certPath: is_string($data['cert_path'] ?? null) ? $data['cert_path'] : '',
            keyPath: is_string($data['key_path'] ?? null) ? $data['key_path'] : '',
            caPath: is_string($data['ca_path'] ?? null) ? $data['ca_path'] : '',
            mutual: is_bool($data['mutual'] ?? null) ? $data['mutual'] : false,
        );
    }

    /**
     * Whether mutual TLS (client certificate verification) is active.
     */
    public function isMutual(): bool
    {
        return $this->enabled && $this->mutual && $this->caPath !== '';
    }
}
