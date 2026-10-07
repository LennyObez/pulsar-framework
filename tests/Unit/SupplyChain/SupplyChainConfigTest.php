<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\License\AllowedLicensesConfig;
use Pulsar\SupplyChain\Pipeline\RequiredToolsConfig;
use Pulsar\SupplyChain\Signing\SigningConfig;
use Pulsar\SupplyChain\SupplyChainConfig;
use Pulsar\SupplyChain\Vex\VexConfig;

use function dirname;

#[CoversClass(SupplyChainConfig::class)]
final class SupplyChainConfigTest extends TestCase
{
    #[Test]
    public function defaultsComposeDefaultSections(): void
    {
        $config = new SupplyChainConfig();

        self::assertInstanceOf(AllowedLicensesConfig::class, $config->licenses);
        self::assertInstanceOf(RequiredToolsConfig::class, $config->pipeline);
        self::assertInstanceOf(VexConfig::class, $config->vex);
        self::assertInstanceOf(SigningConfig::class, $config->signing);
        self::assertContains('MIT', $config->licenses->allowedLicenses);
        self::assertSame('src', $config->vex->sourceDir);
        self::assertSame('dist', $config->signing->artifactDir);
    }

    #[Test]
    public function fromArrayDistributesEverySection(): void
    {
        $config = SupplyChainConfig::fromArray([
            'allowed_licenses' => ['MIT', 'Custom-1.0'],
            'required_pipeline_tools' => ['phpstan', 'semgrep'],
            'vex' => ['source_dir' => 'app', 'output_path' => 'build/vex.json'],
            'signing' => ['artifact_dir' => 'release', 'manifest_path' => 'release/sigs.json'],
        ]);

        self::assertSame(['MIT', 'Custom-1.0'], $config->licenses->allowedLicenses);
        self::assertSame(['phpstan', 'semgrep'], $config->pipeline->requiredTools);
        self::assertSame('app', $config->vex->sourceDir);
        self::assertSame('build/vex.json', $config->vex->outputPath);
        self::assertSame('release', $config->signing->artifactDir);
        self::assertSame('release/sigs.json', $config->signing->manifestPath);
    }

    #[Test]
    public function fromArrayFallsBackToSectionDefaultsWhenSectionsMissing(): void
    {
        $config = SupplyChainConfig::fromArray([]);

        self::assertContains('MIT', $config->licenses->allowedLicenses);
        self::assertContains('phpstan', $config->pipeline->requiredTools);
        self::assertSame('vex.json', $config->vex->outputPath);
        self::assertSame('signatures.json', $config->signing->manifestPath);
    }

    #[Test]
    public function fromArrayIgnoresNonArraySections(): void
    {
        $config = SupplyChainConfig::fromArray([
            'vex' => 'not-an-array',
            'signing' => 42,
        ]);

        self::assertSame('src', $config->vex->sourceDir);
        self::assertSame('dist', $config->signing->artifactDir);
    }

    #[Test]
    public function fromArrayMatchesTheShippedConfigFile(): void
    {
        /** @var array<string, mixed> $shipped */
        $shipped = require dirname(__DIR__, 3) . '/config/supply-chain.php';

        $config = SupplyChainConfig::fromArray($shipped);

        // The shipped file's values flow through to the typed sections.
        self::assertContains('MIT', $config->licenses->allowedLicenses);
        self::assertContains('deptrac', $config->pipeline->requiredTools);
        self::assertSame('src', $config->vex->sourceDir);
        self::assertSame('vex.json', $config->vex->outputPath);
        self::assertSame('dist', $config->signing->artifactDir);
        self::assertSame('signatures.json', $config->signing->manifestPath);
    }
}
