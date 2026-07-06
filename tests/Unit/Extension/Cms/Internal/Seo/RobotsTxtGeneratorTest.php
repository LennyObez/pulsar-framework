<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Seo\RobotsTxtGenerator;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerRobotsPolicy;

use function strpos;

#[CoversClass(RobotsTxtGenerator::class)]
final class RobotsTxtGeneratorTest extends TestCase
{
    #[Test]
    public function generateContainsUserAgentDirective(): void
    {
        $generator = new RobotsTxtGenerator();
        $result = $generator->generate('https://example.com');

        self::assertStringContainsString('User-agent: *', $result);
    }

    #[Test]
    public function generateContainsAllowRoot(): void
    {
        $generator = new RobotsTxtGenerator();
        $result = $generator->generate('https://example.com');

        self::assertStringContainsString('Allow: /', $result);
    }

    #[Test]
    public function generateDisallowsAdminAndApi(): void
    {
        $generator = new RobotsTxtGenerator();
        $result = $generator->generate('https://example.com');

        self::assertStringContainsString('Disallow: /admin/', $result);
        self::assertStringContainsString('Disallow: /api/', $result);
    }

    #[Test]
    public function generateIncludesSitemapUrl(): void
    {
        $generator = new RobotsTxtGenerator();
        $result = $generator->generate('https://example.com');

        self::assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $result);
    }

    #[Test]
    public function generateStripsTrailingSlashFromBaseUrl(): void
    {
        $generator = new RobotsTxtGenerator();
        $result = $generator->generate('https://example.com/');

        self::assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $result);
        self::assertStringNotContainsString('example.com//sitemap', $result);
    }

    #[Test]
    public function generateEndsWithNewline(): void
    {
        $generator = new RobotsTxtGenerator();
        $result = $generator->generate('https://example.com');

        self::assertStringEndsWith("\n", $result);
    }

    #[Test]
    public function appendsAiCrawlerDirectivesBeforeSitemapWhenConfigured(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(enabled: true));
        $generator = new RobotsTxtGenerator($policy);

        $result = $generator->generate('https://example.com');

        self::assertStringContainsString("User-agent: GPTBot\nDisallow: /", $result);
        self::assertStringContainsString('User-agent: Google-Extended', $result);
        self::assertLessThan(
            strpos($result, 'Sitemap:'),
            strpos($result, '# AI crawlers'),
            'AI directives appear before the Sitemap line',
        );
    }

    #[Test]
    public function omitsAiDirectivesWhenPolicyDisabled(): void
    {
        $policy = new AiCrawlerRobotsPolicy(new AiCrawlerConfig(enabled: false));
        $generator = new RobotsTxtGenerator($policy);

        $result = $generator->generate('https://example.com');

        self::assertStringNotContainsString('# AI crawlers', $result);
        self::assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $result);
    }
}
