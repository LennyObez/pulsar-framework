<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexConfig;

use const DIRECTORY_SEPARATOR;

#[CoversClass(VexConfig::class)]
final class VexConfigTest extends TestCase
{
    #[Test]
    public function defaultsMatchFrameworkConventions(): void
    {
        $config = new VexConfig();

        self::assertSame('src', $config->sourceDir);
        self::assertSame('vex.json', $config->outputPath);
    }

    #[Test]
    public function fromArrayReadsSnakeCaseKeys(): void
    {
        $config = VexConfig::fromArray([
            'source_dir' => 'app',
            'output_path' => 'build/vex.json',
        ]);

        self::assertSame('app', $config->sourceDir);
        self::assertSame('build/vex.json', $config->outputPath);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsForMissingOrBlankValues(): void
    {
        $config = VexConfig::fromArray([
            'source_dir' => '',
            'output_path' => 42,
        ]);

        self::assertSame('src', $config->sourceDir);
        self::assertSame('vex.json', $config->outputPath);
    }

    #[Test]
    public function resolvedOutputPathJoinsRelativeValueAgainstRoot(): void
    {
        $config = new VexConfig(outputPath: 'vex.json');

        self::assertSame(
            '/project' . DIRECTORY_SEPARATOR . 'vex.json',
            $config->resolvedOutputPath('/project'),
        );
    }

    #[Test]
    public function resolvedOutputPathKeepsAbsoluteUnixValue(): void
    {
        $config = new VexConfig(outputPath: '/var/out/vex.json');

        self::assertSame('/var/out/vex.json', $config->resolvedOutputPath('/project'));
    }

    #[Test]
    public function resolvedOutputPathKeepsAbsoluteWindowsValue(): void
    {
        $config = new VexConfig(outputPath: 'C:\\out\\vex.json');

        self::assertSame('C:\\out\\vex.json', $config->resolvedOutputPath('/project'));
    }

    #[Test]
    public function resolvedOutputPathTrimsTrailingRootSeparators(): void
    {
        $config = new VexConfig(outputPath: 'vex.json');

        self::assertSame(
            '/project' . DIRECTORY_SEPARATOR . 'vex.json',
            $config->resolvedOutputPath('/project/'),
        );
    }
}
