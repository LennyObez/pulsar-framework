<?php

declare(strict_types=1);

namespace Pulsar\Inertia;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

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
            rootView: is_string($data['root_view'] ?? null) ? $data['root_view'] : 'app',
            versionHeader: is_string($data['version_header'] ?? null) ? $data['version_header'] : 'X-Inertia-Version',
            componentPathPrefix: is_string($data['component_path_prefix'] ?? null) ? $data['component_path_prefix'] : '',
        );
    }
}
