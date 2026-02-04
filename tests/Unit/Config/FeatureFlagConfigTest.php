<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\FeatureFlag\FlagStorageDriver;

#[CoversClass(FeatureFlagConfig::class)]
final class FeatureFlagConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FEATURE_FLAGS_ENABLED');
    }

    protected function tearDown(): void
    {
        putenv('FEATURE_FLAGS_ENABLED');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $environment = Environment::load();

        $data = [
            'enabled' => true,
            'storage' => 'file',
            'file_path' => '/custom/flags.json',
            'audit_evaluations' => true,
            'default_state' => true,
            'flags' => [
                'dark-mode' => ['enabled' => true, 'type' => 'boolean'],
                'rollout' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 50],
            ],
        ];

        $config = FeatureFlagConfig::fromArray($data, $environment);

        self::assertTrue($config->enabled);
        self::assertSame(FlagStorageDriver::File, $config->storage);
        self::assertSame('/custom/flags.json', $config->filePath);
        self::assertTrue($config->auditEvaluations);
        self::assertTrue($config->defaultState);
        self::assertCount(2, $config->flags);
        self::assertArrayHasKey('dark-mode', $config->flags);
        self::assertArrayHasKey('rollout', $config->flags);
    }

    #[Test]
    public function fromArrayWithDefaultsApplied(): void
    {
        $environment = Environment::load();

        $config = FeatureFlagConfig::fromArray([], $environment);

        self::assertFalse($config->enabled);
        self::assertSame(FlagStorageDriver::Memory, $config->storage);
        self::assertSame('storage/flags.json', $config->filePath);
        self::assertFalse($config->auditEvaluations);
        self::assertFalse($config->defaultState);
        self::assertSame([], $config->flags);
    }

    #[Test]
    public function environmentVariableOverridesEnabledSetting(): void
    {
        putenv('FEATURE_FLAGS_ENABLED=true');
        $environment = Environment::load();

        $config = FeatureFlagConfig::fromArray(['enabled' => false], $environment);

        self::assertTrue($config->enabled);
    }
}
