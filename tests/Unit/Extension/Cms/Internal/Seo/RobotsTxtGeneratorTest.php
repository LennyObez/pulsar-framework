<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Seo\RobotsTxtGenerator;

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
}
