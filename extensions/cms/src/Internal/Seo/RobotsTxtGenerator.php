<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Seo\RobotsTxtGeneratorInterface;

use function rtrim;

/**
 * Generates robots.txt content with sitemap reference.
 *
 * @psalm-api Bound to RobotsTxtGeneratorInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use RobotsTxtGeneratorInterface for public API')]
final readonly class RobotsTxtGenerator implements RobotsTxtGeneratorInterface
{
    public function generate(string $baseUrl, ?string $tenantId = null): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $lines = [
            'User-agent: *',
            'Allow: /',
            '',
            'Disallow: /admin/',
            'Disallow: /api/',
            '',
            "Sitemap: $baseUrl/sitemap.xml",
        ];

        return implode("\n", $lines) . "\n";
    }
}
