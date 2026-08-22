<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * TLS and mTLS configuration for the gRPC server.
 * @api
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
     * @param array{
     *     enabled?: bool,
     *     cert_path?: string,
     *     key_path?: string,
     *     ca_path?: string,
     *     mutual?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? false,
            certPath: $data['cert_path'] ?? '',
            keyPath: $data['key_path'] ?? '',
            caPath: $data['ca_path'] ?? '',
            mutual: $data['mutual'] ?? false,
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
