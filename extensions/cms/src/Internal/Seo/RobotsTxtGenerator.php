<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Seo\RobotsTxtGeneratorInterface;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerRobotsPolicy;

use function implode;
use function rtrim;

/**
 * Generates robots.txt content with sitemap reference.
 *
 * When the AI-crawler defense is configured, its advisory robots.txt directives
 * (the only control point for opt-out-only tokens such as Google-Extended) are
 * appended, so the advisory and enforced policies stay in lockstep.
 *
 * @psalm-api Bound to RobotsTxtGeneratorInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use RobotsTxtGeneratorInterface for public API')]
final readonly class RobotsTxtGenerator implements RobotsTxtGeneratorInterface
{
    public function __construct(
        private ?AiCrawlerRobotsPolicy $aiCrawlerPolicy = null,
    ) {}

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
        ];

        $aiDirectives = $this->aiCrawlerPolicy?->directives() ?? '';
        if ($aiDirectives !== '') {
            $lines[] = $aiDirectives;
            $lines[] = '';
        }

        $lines[] = "Sitemap: $baseUrl/sitemap.xml";

        return implode("\n", $lines) . "\n";
    }
}
