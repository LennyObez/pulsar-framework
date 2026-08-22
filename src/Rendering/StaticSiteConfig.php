<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for static site generation (SSG).
 * @api
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
            outputDir: Coerce::string($data['output_dir'] ?? null, 'public/static'),
            baseUrl: Coerce::string($data['base_url'] ?? null),
            excludePatterns: Coerce::listOfString(
                $data['exclude_patterns'] ?? null,
                ['/api/*', '/admin/*', '/_*'],
            ),
        );
    }
}
