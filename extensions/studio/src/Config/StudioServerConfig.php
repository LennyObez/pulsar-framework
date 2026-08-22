<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;

/**
 * Server configuration for the Studio development server.
 */
#[Internal]
final readonly class StudioServerConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8585,
        public string $documentRoot = 'extensions/studio/dev/public',
    ) {}

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     document_root?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $host = $environment->get('STUDIO_HOST') ?? $data['host'] ?? '127.0.0.1';

        $portEnv = $environment->get('STUDIO_PORT');
        $port = $portEnv !== null ? (int) $portEnv : ($data['port'] ?? 8585);

        return new self(
            host: $host,
            port: $port,
            documentRoot: $data['document_root'] ?? 'extensions/studio/dev/public',
        );
    }
}
