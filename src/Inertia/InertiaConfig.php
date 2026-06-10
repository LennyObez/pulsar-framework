<?php

declare(strict_types=1);

namespace Pulsar\Inertia;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for the Inertia-style SPA bridge.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InertiaConfig
{
    public function __construct(
        public string $rootView = 'app',
        public string $versionHeader = 'X-Inertia-Version',
        public string $componentPathPrefix = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            rootView: Coerce::string($data['root_view'] ?? null, 'app'),
            versionHeader: Coerce::string($data['version_header'] ?? null, 'X-Inertia-Version'),
            componentPathPrefix: Coerce::string($data['component_path_prefix'] ?? null),
        );
    }
}
