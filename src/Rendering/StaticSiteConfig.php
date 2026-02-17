<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Configuration for static site generation (SSG).
 */
#[Api(since: '1.0.0')]
final readonly class StaticSiteConfig
{
    /**
     * @param list<string> $excludePatterns Glob patterns for paths to exclude
     */
    public function __construct(
        public string $outputDir = 'public/static',
        public string $baseUrl = '',
        public array $excludePatterns = ['/api/*', '/admin/*', '/_*'],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            outputDir: is_string($data['output_dir'] ?? null) ? $data['output_dir'] : 'public/static',
            baseUrl: is_string($data['base_url'] ?? null) ? $data['base_url'] : '',
            excludePatterns: is_array($data['exclude_patterns'] ?? null)
                ? array_values(array_filter($data['exclude_patterns'], 'is_string'))
                : ['/api/*', '/admin/*', '/_*'],
        );
    }
}
