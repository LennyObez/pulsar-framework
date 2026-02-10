<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Sandbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Sandbox\SandboxConfig;
use Pulsar\View\ViewConfig;

#[CoversClass(SandboxConfig::class)]
final class SandboxConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new SandboxConfig();

        self::assertSame(10_000, $config->stepLimit);
        self::assertSame(1_000, $config->loopLimit);
        self::assertSame(1_048_576, $config->outputSizeLimit);
        self::assertSame(500, $config->wallClockCheckInterval);
        self::assertSame(5.0, $config->wallClockLimitSeconds);
        self::assertSame([], $config->includeAllowlist);
    }

    #[Test]
    public function customValues(): void
    {
        $config = new SandboxConfig(
            stepLimit: 5_000,
            loopLimit: 500,
            outputSizeLimit: 524288,
            wallClockCheckInterval: 250,
            wallClockLimitSeconds: 2.5,
            includeAllowlist: ['header' => '<h1>Header</h1>'],
        );

        self::assertSame(5_000, $config->stepLimit);
        self::assertSame(500, $config->loopLimit);
        self::assertSame(524288, $config->outputSizeLimit);
        self::assertSame(250, $config->wallClockCheckInterval);
        self::assertSame(2.5, $config->wallClockLimitSeconds);
    }

    #[Test]
    public function isIncludeAllowedReturnsTrueForAllowlistedTemplate(): void
    {
        $config = new SandboxConfig(includeAllowlist: ['header' => '<h1>H</h1>']);

        self::assertTrue($config->isIncludeAllowed('header'));
    }

    #[Test]
    public function isIncludeAllowedReturnsFalseForUnknownTemplate(): void
    {
        $config = new SandboxConfig(includeAllowlist: ['header' => '<h1>H</h1>']);

        self::assertFalse($config->isIncludeAllowed('footer'));
    }

    #[Test]
    public function getIncludeContentReturnsContentForAllowlisted(): void
    {
        $config = new SandboxConfig(includeAllowlist: ['header' => '<h1>Hello</h1>']);

        self::assertSame('<h1>Hello</h1>', $config->getIncludeContent('header'));
    }

    #[Test]
    public function getIncludeContentReturnsNullForUnknown(): void
    {
        $config = new SandboxConfig();

        self::assertNull($config->getIncludeContent('unknown'));
    }

    #[Test]
    public function fromViewConfigCreatesInstance(): void
    {
        $viewConfig = new ViewConfig(
            templatePaths: ['/templates'],
            cachePath: '/cache',
            sandboxStepLimit: 20_000,
            sandboxLoopLimit: 2_000,
            sandboxOutputSizeLimit: 2_097_152,
            sandboxWallClockCheckInterval: 1_000,
        );

        $config = SandboxConfig::fromViewConfig($viewConfig);

        self::assertSame(20_000, $config->stepLimit);
        self::assertSame(2_000, $config->loopLimit);
        self::assertSame(2_097_152, $config->outputSizeLimit);
        self::assertSame(1_000, $config->wallClockCheckInterval);
    }
}
