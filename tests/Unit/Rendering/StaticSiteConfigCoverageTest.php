<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\StaticSiteConfig;

/**
 * Edge case coverage for StaticSiteConfig::fromArray().
 */
#[CoversClass(StaticSiteConfig::class)]
final class StaticSiteConfigCoverageTest extends TestCase
{
    #[Test]
    public function fromArrayFiltersNonStringExcludePatterns(): void
    {
        $config = StaticSiteConfig::fromArray([
            'exclude_patterns' => ['/valid/*', 123, null, '/also-valid/*', false],
        ]);

        self::assertSame(['/valid/*', '/also-valid/*'], $config->excludePatterns);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = StaticSiteConfig::fromArray([
            'output_dir' => null,
            'base_url' => null,
            'exclude_patterns' => null,
        ]);

        self::assertSame('public/static', $config->outputDir);
        self::assertSame('', $config->baseUrl);
        self::assertSame(['/api/*', '/admin/*', '/_*'], $config->excludePatterns);
    }

    #[Test]
    public function fromArrayWithEmptyStringValues(): void
    {
        $config = StaticSiteConfig::fromArray([
            'output_dir' => '',
            'base_url' => '',
        ]);

        // Empty strings are still strings, so they're accepted
        self::assertSame('', $config->outputDir);
        self::assertSame('', $config->baseUrl);
    }

    #[Test]
    public function fromArrayWithEmptyExcludePatterns(): void
    {
        $config = StaticSiteConfig::fromArray([
            'exclude_patterns' => [],
        ]);

        self::assertSame([], $config->excludePatterns);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidOutputDirProvider(): iterable
    {
        yield 'integer' => [42];
        yield 'float' => [3.14];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'array' => [['nested']];
    }

    #[Test]
    #[DataProvider('invalidOutputDirProvider')]
    public function fromArrayFallsBackForInvalidOutputDir(mixed $value): void
    {
        $config = StaticSiteConfig::fromArray(['output_dir' => $value]);

        self::assertSame('public/static', $config->outputDir);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidBaseUrlProvider(): iterable
    {
        yield 'integer' => [42];
        yield 'array' => [['https://example.com']];
        yield 'boolean' => [true];
    }

    #[Test]
    #[DataProvider('invalidBaseUrlProvider')]
    public function fromArrayFallsBackForInvalidBaseUrl(mixed $value): void
    {
        $config = StaticSiteConfig::fromArray(['base_url' => $value]);

        self::assertSame('', $config->baseUrl);
    }

    #[Test]
    public function fromArrayWithAllValidData(): void
    {
        $config = StaticSiteConfig::fromArray([
            'output_dir' => '/var/www/static',
            'base_url' => 'https://cdn.example.com',
            'exclude_patterns' => ['/internal/*'],
        ]);

        self::assertSame('/var/www/static', $config->outputDir);
        self::assertSame('https://cdn.example.com', $config->baseUrl);
        self::assertSame(['/internal/*'], $config->excludePatterns);
    }
}
