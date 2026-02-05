<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;

use Pulsar\Api\Internal;

/**
 * Server configuration for the Studio development server.
 */
#[Internal]
readonly class StudioServerConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8585,
        public string $documentRoot = 'public',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        /** @var string $host */
        $host = $environment->get('STUDIO_HOST') ?? ($data['host'] ?? '127.0.0.1');

        $portEnv = $environment->get('STUDIO_PORT');
        if ($portEnv !== null) {
            $port = (int) $portEnv;
        } else {
            $rawPort = $data['port'] ?? 8585;
            $port = is_int($rawPort) ? $rawPort : (int) (is_numeric($rawPort) ? $rawPort : 8585);
        }

        /** @var string $documentRoot */
        $documentRoot = $data['document_root'] ?? 'public';

        return new self(
            host: $host,
            port: $port,
            documentRoot: $documentRoot,
        );
    }
}
