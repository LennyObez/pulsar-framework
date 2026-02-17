<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\StaticSiteConfig;

#[CoversClass(StaticSiteConfig::class)]
final class StaticSiteConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new StaticSiteConfig();

        self::assertSame('public/static', $config->outputDir);
        self::assertSame('', $config->baseUrl);
        self::assertSame(['/api/*', '/admin/*', '/_*'], $config->excludePatterns);
    }

    #[Test]
    public function custom_values(): void
    {
        $config = new StaticSiteConfig(
            outputDir: '/var/www/dist',
            baseUrl: 'https://example.com',
            excludePatterns: ['/private/*'],
        );

        self::assertSame('/var/www/dist', $config->outputDir);
        self::assertSame('https://example.com', $config->baseUrl);
        self::assertSame(['/private/*'], $config->excludePatterns);
    }

    #[Test]
    public function from_array_with_all_keys(): void
    {
        $config = StaticSiteConfig::fromArray([
            'output_dir' => '/build',
            'base_url' => 'https://site.io',
            'exclude_patterns' => ['/secret/*'],
        ]);

        self::assertSame('/build', $config->outputDir);
        self::assertSame('https://site.io', $config->baseUrl);
        self::assertSame(['/secret/*'], $config->excludePatterns);
    }

    #[Test]
    public function from_array_uses_defaults_for_missing_keys(): void
    {
        $config = StaticSiteConfig::fromArray([]);

        self::assertSame('public/static', $config->outputDir);
        self::assertSame('', $config->baseUrl);
        self::assertSame(['/api/*', '/admin/*', '/_*'], $config->excludePatterns);
    }

    #[Test]
    public function from_array_ignores_invalid_types(): void
    {
        $config = StaticSiteConfig::fromArray([
            'output_dir' => 123,
            'base_url' => false,
            'exclude_patterns' => 'not-array',
        ]);

        self::assertSame('public/static', $config->outputDir);
        self::assertSame('', $config->baseUrl);
        self::assertSame(['/api/*', '/admin/*', '/_*'], $config->excludePatterns);
    }
}
