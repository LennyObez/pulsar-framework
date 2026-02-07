<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Seo\RobotsTxtGenerator;

#[CoversClass(RobotsTxtGenerator::class)]
final class RobotsTxtGeneratorTest extends TestCase
{
    // -- Structure ------------------------------------------------------------

    #[Test]
    public function test_output_starts_with_user_agent_wildcard(): void
    {
        $generator = new RobotsTxtGenerator();

        $output = $generator->generate('https://example.com');

        self::assertStringStartsWith('User-agent: *', $output);
    }

    #[Test]
    public function test_contains_sitemap_directive_with_url(): void
    {
        $generator = new RobotsTxtGenerator();

        $output = $generator->generate('https://example.com');

        self::assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $output);
    }

    #[Test]
    public function test_contains_allow_rule(): void
    {
        $generator = new RobotsTxtGenerator();

        $output = $generator->generate('https://example.com');

        self::assertStringContainsString('Allow: /', $output);
    }

    #[Test]
    public function test_contains_disallow_admin_and_api(): void
    {
        $generator = new RobotsTxtGenerator();

        $output = $generator->generate('https://example.com');

        self::assertStringContainsString('Disallow: /admin/', $output);
        self::assertStringContainsString('Disallow: /api/', $output);
    }

    #[Test]
    public function test_output_ends_with_newline(): void
    {
        $generator = new RobotsTxtGenerator();

        $output = $generator->generate('https://example.com');

        self::assertStringEndsWith("\n", $output);
    }

    #[Test]
    public function test_trailing_slash_stripped_from_base_url(): void
    {
        $generator = new RobotsTxtGenerator();

        $output = $generator->generate('https://example.com/');

        self::assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $output);
        self::assertStringNotContainsString('https://example.com//sitemap.xml', $output);
    }

    #[Test]
    public function test_output_has_no_syntax_errors(): void
    {
        $generator = new RobotsTxtGenerator();
        $output = $generator->generate('https://example.com');

        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            // Each non-empty line must be a valid robots.txt directive
            self::assertMatchesRegularExpression(
                '/^(User-agent|Allow|Disallow|Sitemap|Crawl-delay|Host|Sitemap):\s*.+$/i',
                $line,
                "Invalid robots.txt directive: '{$line}'",
            );
        }
    }
}
