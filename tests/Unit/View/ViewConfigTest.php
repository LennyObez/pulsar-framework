<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\ViewConfig;
use ReflectionClass;

#[CoversClass(ViewConfig::class)]
final class ViewConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/app/views', '/vendor/views'],
            cachePath: '/tmp/cache',
            autoEscape: false,
            activeTheme: 'dark',
            phpDirectiveAllowed: true,
            sandboxStepLimit: 5_000,
            sandboxLoopLimit: 500,
            sandboxOutputSizeLimit: 512_000,
            sandboxWallClockCheckInterval: 250,
        );

        self::assertSame(['/app/views', '/vendor/views'], $config->templatePaths);
        self::assertSame('/tmp/cache', $config->cachePath);
        self::assertFalse($config->autoEscape);
        self::assertSame('dark', $config->activeTheme);
        self::assertTrue($config->phpDirectiveAllowed);
        self::assertSame(5_000, $config->sandboxStepLimit);
        self::assertSame(500, $config->sandboxLoopLimit);
        self::assertSame(512_000, $config->sandboxOutputSizeLimit);
        self::assertSame(250, $config->sandboxWallClockCheckInterval);
    }

    #[Test]
    public function constructorUsesDefaults(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
        );

        self::assertTrue($config->autoEscape);
        self::assertSame('default', $config->activeTheme);
        self::assertFalse($config->phpDirectiveAllowed);
        self::assertSame(10_000, $config->sandboxStepLimit);
        self::assertSame(1_000, $config->sandboxLoopLimit);
        self::assertSame(1_048_576, $config->sandboxOutputSizeLimit);
        self::assertSame(500, $config->sandboxWallClockCheckInterval);
    }

    #[Test]
    public function fromArrayBuildsFromFullConfig(): void
    {
        $config = ViewConfig::fromArray([
            'template_paths' => ['/app/views', '/vendor/views'],
            'cache_path' => '/tmp/cache',
            'auto_escape' => false,
            'active_theme' => 'dark',
            'php_directive_allowed' => true,
            'sandbox_step_limit' => 20_000,
            'sandbox_loop_limit' => 2_000,
            'sandbox_output_size_limit' => 2_000_000,
            'sandbox_wall_clock_check_interval' => 1_000,
        ]);

        self::assertSame(['/app/views', '/vendor/views'], $config->templatePaths);
        self::assertSame('/tmp/cache', $config->cachePath);
        self::assertFalse($config->autoEscape);
        self::assertSame('dark', $config->activeTheme);
        self::assertTrue($config->phpDirectiveAllowed);
        self::assertSame(20_000, $config->sandboxStepLimit);
        self::assertSame(2_000, $config->sandboxLoopLimit);
        self::assertSame(2_000_000, $config->sandboxOutputSizeLimit);
        self::assertSame(1_000, $config->sandboxWallClockCheckInterval);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingKeys(): void
    {
        $config = ViewConfig::fromArray([]);

        self::assertSame([], $config->templatePaths);
        self::assertSame('', $config->cachePath);
        self::assertTrue($config->autoEscape);
        self::assertSame('default', $config->activeTheme);
        self::assertFalse($config->phpDirectiveAllowed);
        self::assertSame(10_000, $config->sandboxStepLimit);
        self::assertSame(1_000, $config->sandboxLoopLimit);
        self::assertSame(1_048_576, $config->sandboxOutputSizeLimit);
        self::assertSame(500, $config->sandboxWallClockCheckInterval);
    }

    #[Test]
    public function fromArrayFiltersNonStringTemplatePaths(): void
    {
        $config = ViewConfig::fromArray([
            'template_paths' => ['/valid', 42, null, '', '/also-valid', true],
            'cache_path' => '/cache',
        ]);

        self::assertSame(['/valid', '/also-valid'], $config->templatePaths);
    }

    #[Test]
    public function fromArrayHandlesNonArrayTemplatePaths(): void
    {
        $config = ViewConfig::fromArray([
            'template_paths' => 'not-an-array',
            'cache_path' => '/cache',
        ]);

        self::assertSame([], $config->templatePaths);
    }

    #[Test]
    public function fromArrayHandlesNonStringCachePath(): void
    {
        $config = ViewConfig::fromArray([
            'cache_path' => 42,
        ]);

        self::assertSame('', $config->cachePath);
    }

    #[Test]
    public function fromArrayHandlesNonBoolAutoEscape(): void
    {
        $config = ViewConfig::fromArray([
            'auto_escape' => 'yes',
        ]);

        self::assertTrue($config->autoEscape);
    }

    #[Test]
    public function fromArrayHandlesNonStringTheme(): void
    {
        $config = ViewConfig::fromArray([
            'active_theme' => 42,
        ]);

        self::assertSame('default', $config->activeTheme);
    }

    #[Test]
    public function fromArrayHandlesNonBoolPhpDirectiveAllowed(): void
    {
        $config = ViewConfig::fromArray([
            'php_directive_allowed' => 1,
        ]);

        self::assertFalse($config->phpDirectiveAllowed);
    }

    #[Test]
    public function fromArrayRejectsNonPositiveSandboxLimits(): void
    {
        $config = ViewConfig::fromArray([
            'sandbox_step_limit' => 0,
            'sandbox_loop_limit' => -1,
            'sandbox_output_size_limit' => 0,
            'sandbox_wall_clock_check_interval' => -100,
        ]);

        self::assertSame(10_000, $config->sandboxStepLimit);
        self::assertSame(1_000, $config->sandboxLoopLimit);
        self::assertSame(1_048_576, $config->sandboxOutputSizeLimit);
        self::assertSame(500, $config->sandboxWallClockCheckInterval);
    }

    #[Test]
    public function fromArrayRejectsNonIntSandboxLimits(): void
    {
        $config = ViewConfig::fromArray([
            'sandbox_step_limit' => 'ten',
            'sandbox_loop_limit' => 5.5,
            'sandbox_output_size_limit' => null,
            'sandbox_wall_clock_check_interval' => true,
        ]);

        self::assertSame(10_000, $config->sandboxStepLimit);
        self::assertSame(1_000, $config->sandboxLoopLimit);
        self::assertSame(1_048_576, $config->sandboxOutputSizeLimit);
        self::assertSame(500, $config->sandboxWallClockCheckInterval);
    }

    #[Test]
    public function isReadonly(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
        );

        $reflection = new ReflectionClass($config);

        self::assertTrue($reflection->isReadOnly());
    }
}
