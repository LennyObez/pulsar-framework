<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Pipeline\RequiredToolsConfig;

#[CoversClass(RequiredToolsConfig::class)]
final class RequiredToolsConfigTest extends TestCase
{
    #[Test]
    public function defaultRequiredToolsContainsExpectedTools(): void
    {
        $config = new RequiredToolsConfig();

        self::assertContains('phpstan', $config->requiredTools);
        self::assertContains('psalm', $config->requiredTools);
        self::assertContains('composer-audit', $config->requiredTools);
        self::assertContains('deptrac', $config->requiredTools);
    }

    #[Test]
    public function defaultRequiredToolsHasExactlyFourEntries(): void
    {
        $config = new RequiredToolsConfig();

        self::assertCount(4, $config->requiredTools);
    }

    #[Test]
    public function defaultDetectionPatternsIncludeAllRequiredTools(): void
    {
        $config = new RequiredToolsConfig();

        foreach ($config->requiredTools as $tool) {
            self::assertArrayHasKey($tool, $config->detectionPatterns);
        }
    }

    #[Test]
    public function defaultDetectionPatternsIncludeSemgrep(): void
    {
        $config = new RequiredToolsConfig();

        self::assertArrayHasKey('semgrep', $config->detectionPatterns);
        self::assertContains('semgrep scan', $config->detectionPatterns['semgrep']);
    }

    #[Test]
    public function phpstanDetectionPatternsIncludeCommonVariants(): void
    {
        $config = new RequiredToolsConfig();

        $patterns = $config->detectionPatterns['phpstan'];
        self::assertContains('phpstan', $patterns);
        self::assertContains('phpstan analyse', $patterns);
        self::assertContains('phpstan analyze', $patterns);
    }

    #[Test]
    public function customRequiredToolsOverridesDefaults(): void
    {
        $custom = ['eslint', 'prettier'];
        $config = new RequiredToolsConfig(requiredTools: $custom);

        self::assertSame($custom, $config->requiredTools);
    }

    #[Test]
    public function customDetectionPatternsOverridesDefaults(): void
    {
        $custom = ['eslint' => ['eslint', 'npx eslint']];
        $config = new RequiredToolsConfig(detectionPatterns: $custom);

        self::assertSame($custom, $config->detectionPatterns);
        self::assertArrayNotHasKey('phpstan', $config->detectionPatterns);
    }

    #[Test]
    public function emptyArrayOverridesDefaultsForRequiredTools(): void
    {
        $config = new RequiredToolsConfig(requiredTools: []);

        self::assertSame([], $config->requiredTools);
    }

    #[Test]
    public function emptyArrayOverridesDefaultsForDetectionPatterns(): void
    {
        $config = new RequiredToolsConfig(detectionPatterns: []);

        self::assertSame([], $config->detectionPatterns);
    }

    #[Test]
    public function nullUsesDefaultsForBothFields(): void
    {
        $config = new RequiredToolsConfig(null, null);

        self::assertNotEmpty($config->requiredTools);
        self::assertNotEmpty($config->detectionPatterns);
    }

    #[Test]
    public function canOverrideToolsWhileKeepingDefaultPatterns(): void
    {
        $config = new RequiredToolsConfig(requiredTools: ['semgrep']);

        self::assertSame(['semgrep'], $config->requiredTools);
        // Detection patterns still include all defaults since we only overrode tools
        self::assertArrayHasKey('phpstan', $config->detectionPatterns);
        self::assertArrayHasKey('semgrep', $config->detectionPatterns);
    }

    #[Test]
    public function composerAuditDetectionPatternsIncludeVariants(): void
    {
        $config = new RequiredToolsConfig();

        $patterns = $config->detectionPatterns['composer-audit'];
        self::assertContains('composer audit', $patterns);
        self::assertContains('composer vulnerability', $patterns);
    }

    #[Test]
    public function fromArrayReadsRequiredPipelineToolsKey(): void
    {
        $config = RequiredToolsConfig::fromArray([
            'required_pipeline_tools' => ['phpstan', 'semgrep'],
        ]);

        self::assertSame(['phpstan', 'semgrep'], $config->requiredTools);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsWhenToolsMissing(): void
    {
        $config = RequiredToolsConfig::fromArray(['unrelated' => true]);

        self::assertContains('phpstan', $config->requiredTools);
        self::assertContains('deptrac', $config->requiredTools);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsWhenToolsNotAnArray(): void
    {
        $config = RequiredToolsConfig::fromArray(['required_pipeline_tools' => 'phpstan']);

        self::assertContains('phpstan', $config->requiredTools);
        self::assertContains('psalm', $config->requiredTools);
    }

    #[Test]
    public function fromArrayDropsNonStringToolEntries(): void
    {
        $config = RequiredToolsConfig::fromArray([
            'required_pipeline_tools' => ['phpstan', 99, 'psalm', ['x']],
        ]);

        self::assertSame(['phpstan', 'psalm'], $config->requiredTools);
    }

    #[Test]
    public function fromArrayUsesDefaultDetectionPatternsWhenAbsent(): void
    {
        $config = RequiredToolsConfig::fromArray([
            'required_pipeline_tools' => ['phpstan'],
        ]);

        self::assertArrayHasKey('phpstan', $config->detectionPatterns);
        self::assertContains('phpstan', $config->detectionPatterns['phpstan']);
    }

    #[Test]
    public function fromArrayOverridesDetectionPatternsWhenWellFormed(): void
    {
        $config = RequiredToolsConfig::fromArray([
            'required_pipeline_tools' => ['trivy'],
            'detection_patterns' => ['trivy' => ['trivy fs', 'trivy config']],
        ]);

        self::assertSame(['trivy' => ['trivy fs', 'trivy config']], $config->detectionPatterns);
    }

    #[Test]
    public function fromArrayFiltersMalformedDetectionPatternEntries(): void
    {
        $config = RequiredToolsConfig::fromArray([
            'detection_patterns' => [
                'trivy' => ['trivy fs', 42, 'trivy config'],
                7 => ['ignored non-string key'],
                'bad' => 'not-a-list',
            ],
        ]);

        self::assertSame(
            ['trivy' => ['trivy fs', 'trivy config']],
            $config->detectionPatterns,
        );
    }
}
