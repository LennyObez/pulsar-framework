<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\IntegrityPolicyMode;

#[CoversClass(IntegrityConfig::class)]
final class IntegrityConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('INTEGRITY_ENABLED');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('INTEGRITY_ENABLED');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'enabled' => true,
            'manifest_path' => 'storage/integrity/checksums.json',
            'mode' => 'strict',
            'include' => ['src/**/*.php', 'lib/**/*.php'],
            'exclude' => ['vendor/**', 'tests/**'],
        ];

        $config = IntegrityConfig::fromArray($data, $this->environment);

        self::assertTrue($config->enabled);
        self::assertSame('storage/integrity/checksums.json', $config->manifestPath);
        self::assertSame(IntegrityPolicyMode::Strict, $config->mode);
        self::assertSame(['src/**/*.php', 'lib/**/*.php'], $config->include);
        self::assertSame(['vendor/**', 'tests/**'], $config->exclude);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = IntegrityConfig::fromArray([], $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame('var/integrity/manifest.json', $config->manifestPath);
        self::assertSame(IntegrityPolicyMode::Warn, $config->mode);
        self::assertSame(['src/**/*.php', 'config/**/*.php', 'bin/*'], $config->include);
        self::assertSame(['vendor/**', 'var/**', 'node_modules/**', '.git/**'], $config->exclude);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToTrue(): void
    {
        putenv('INTEGRITY_ENABLED=true');
        $environment = Environment::load();

        $config = IntegrityConfig::fromArray([
            'enabled' => false,
        ], $environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToFalse(): void
    {
        putenv('INTEGRITY_ENABLED=false');
        $environment = Environment::load();

        $config = IntegrityConfig::fromArray([
            'enabled' => true,
        ], $environment);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function configArrayEnabledUsedWhenNoEnvironmentVariable(): void
    {
        $config = IntegrityConfig::fromArray([
            'enabled' => true,
        ], $this->environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function modeEnumWarnIsDefault(): void
    {
        $config = IntegrityConfig::fromArray([], $this->environment);

        self::assertSame(IntegrityPolicyMode::Warn, $config->mode);
    }

    #[Test]
    public function modeEnumStrictFromArray(): void
    {
        $config = IntegrityConfig::fromArray([
            'mode' => 'strict',
        ], $this->environment);

        self::assertSame(IntegrityPolicyMode::Strict, $config->mode);
    }

    #[Test]
    public function partialDataFillsRemainingWithDefaults(): void
    {
        $data = [
            'manifest_path' => 'custom/path.json',
            'mode' => 'strict',
        ];

        $config = IntegrityConfig::fromArray($data, $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame('custom/path.json', $config->manifestPath);
        self::assertSame(IntegrityPolicyMode::Strict, $config->mode);
        self::assertSame(['src/**/*.php', 'config/**/*.php', 'bin/*'], $config->include);
        self::assertSame(['vendor/**', 'var/**', 'node_modules/**', '.git/**'], $config->exclude);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new IntegrityConfig();

        self::assertFalse($config->enabled);
        self::assertSame('var/integrity/manifest.json', $config->manifestPath);
        self::assertSame(IntegrityPolicyMode::Warn, $config->mode);
        self::assertSame(['src/**/*.php', 'config/**/*.php', 'bin/*'], $config->include);
        self::assertSame(['vendor/**', 'var/**', 'node_modules/**', '.git/**'], $config->exclude);
    }
}
