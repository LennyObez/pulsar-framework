<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\StaticSiteConfig;

/**
 * Edge case tests for StaticSiteConfig.
 */
#[CoversClass(StaticSiteConfig::class)]
final class StaticSiteConfigEdgeTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new StaticSiteConfig();

        self::assertSame('public/static', $config->outputDir);
        self::assertSame('', $config->baseUrl);
        self::assertSame(['/api/*', '/admin/*', '/_*'], $config->excludePatterns);
    }

    #[Test]
    public function constructorCustomValues(): void
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
    public function fromArrayBuildsFromFullConfig(): void
    {
        $config = StaticSiteConfig::fromArray([
            'output_dir' => '/output',
            'base_url' => 'https://test.com',
            'exclude_patterns' => ['/hidden/*'],
        ]);

        self::assertSame('/output', $config->outputDir);
        self::assertSame('https://test.com', $config->baseUrl);
        self::assertSame(['/hidden/*'], $config->excludePatterns);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingKeys(): void
    {
        $config = StaticSiteConfig::fromArray([]);

        self::assertSame('public/static', $config->outputDir);
        self::assertSame('', $config->baseUrl);
        self::assertSame(['/api/*', '/admin/*', '/_*'], $config->excludePatterns);
    }

    #[Test]
    public function fromArrayHandlesNonStringOutputDir(): void
    {
        $config = StaticSiteConfig::fromArray(['output_dir' => 42]);

        self::assertSame('public/static', $config->outputDir);
    }

    #[Test]
    public function fromArrayHandlesNonStringBaseUrl(): void
    {
        $config = StaticSiteConfig::fromArray(['base_url' => true]);

        self::assertSame('', $config->baseUrl);
    }

    #[Test]
    public function fromArrayHandlesNonArrayExcludePatterns(): void
    {
        $config = StaticSiteConfig::fromArray(['exclude_patterns' => 'not-array']);

        self::assertSame(['/api/*', '/admin/*', '/_*'], $config->excludePatterns);
    }

    #[Test]
    public function fromArrayFiltersNonStringExcludePatterns(): void
    {
        $config = StaticSiteConfig::fromArray([
            'exclude_patterns' => ['/valid/*', 42, null, '/also-valid/*'],
        ]);

        self::assertSame(['/valid/*', '/also-valid/*'], $config->excludePatterns);
    }
}
