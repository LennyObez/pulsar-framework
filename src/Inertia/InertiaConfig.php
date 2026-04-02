<?php

declare(strict_types=1);

namespace Pulsar\Inertia;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     root_view?: string,
     *     version_header?: string,
     *     component_path_prefix?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            rootView: $data['root_view'] ?? 'app',
            versionHeader: $data['version_header'] ?? 'X-Inertia-Version',
            componentPathPrefix: $data['component_path_prefix'] ?? '',
        );
    }
}
