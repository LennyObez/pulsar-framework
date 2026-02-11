<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\Environment;

#[CoversClass(AuditConfig::class)]
final class AuditConfigTest extends TestCase
{
    private Environment $emptyEnv;

    protected function setUp(): void
    {
        $this->emptyEnv = Environment::load('/nonexistent/.env');
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new AuditConfig();

        self::assertTrue($config->enabled);
        self::assertSame('var/logs/audit.jsonl', $config->logPath);
        self::assertSame([], $config->events);
    }

    #[Test]
    public function constructorWithCustomValues(): void
    {
        $config = new AuditConfig(
            enabled: false,
            logPath: '/var/log/app/audit.jsonl',
            events: ['user.login', 'user.logout', 'order.created'],
        );

        self::assertFalse($config->enabled);
        self::assertSame('/var/log/app/audit.jsonl', $config->logPath);
        self::assertSame(['user.login', 'user.logout', 'order.created'], $config->events);
    }

    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = AuditConfig::fromArray([], $this->emptyEnv);

        self::assertTrue($config->enabled);
        self::assertSame('var/logs/audit.jsonl', $config->logPath);
        self::assertSame([], $config->events);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = AuditConfig::fromArray([
            'enabled' => false,
            'log_path' => 'storage/audit/events.jsonl',
            'events' => ['auth.login', 'auth.logout', 'data.export'],
        ], $this->emptyEnv);

        self::assertFalse($config->enabled);
        self::assertSame('storage/audit/events.jsonl', $config->logPath);
        self::assertSame(['auth.login', 'auth.logout', 'data.export'], $config->events);
    }

    #[Test]
    public function fromArrayEnabledDefaultsToTrue(): void
    {
        $config = AuditConfig::fromArray([], $this->emptyEnv);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayEnabledCoercesFalsy(): void
    {
        $config = AuditConfig::fromArray(['enabled' => 0], $this->emptyEnv);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayEnabledCoercesTruthy(): void
    {
        $config = AuditConfig::fromArray(['enabled' => 'yes'], $this->emptyEnv);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayFallsBackOnNonStringLogPath(): void
    {
        $config = AuditConfig::fromArray([
            'log_path' => 42,
        ], $this->emptyEnv);

        self::assertSame('var/logs/audit.jsonl', $config->logPath);
    }

    #[Test]
    public function fromArrayFallsBackOnNullLogPath(): void
    {
        $config = AuditConfig::fromArray([
            'log_path' => null,
        ], $this->emptyEnv);

        self::assertSame('var/logs/audit.jsonl', $config->logPath);
    }

    #[Test]
    public function fromArrayEventsPassthrough(): void
    {
        $events = ['security.breach', 'compliance.violation', 'data.access'];

        $config = AuditConfig::fromArray([
            'events' => $events,
        ], $this->emptyEnv);

        self::assertSame($events, $config->events);
    }

    #[Test]
    public function fromArrayEmptyEvents(): void
    {
        $config = AuditConfig::fromArray([
            'events' => [],
        ], $this->emptyEnv);

        self::assertSame([], $config->events);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesLogPath(): void
    {
        $envFile = $this->createTempEnvFile(['AUDIT_LOG_PATH' => '/env/path/audit.jsonl']);
        $env = Environment::load($envFile);

        $config = AuditConfig::fromArray([
            'log_path' => 'config/path/audit.jsonl',
        ], $env);

        self::assertSame('/env/path/audit.jsonl', $config->logPath);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesDefaultLogPath(): void
    {
        $envFile = $this->createTempEnvFile(['AUDIT_LOG_PATH' => '/custom/audit.jsonl']);
        $env = Environment::load($envFile);

        $config = AuditConfig::fromArray([], $env);

        self::assertSame('/custom/audit.jsonl', $config->logPath);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayConfigLogPathUsedWhenNoEnvironmentOverride(): void
    {
        $config = AuditConfig::fromArray([
            'log_path' => 'custom/audit.jsonl',
        ], $this->emptyEnv);

        self::assertSame('custom/audit.jsonl', $config->logPath);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = AuditConfig::fromArray([
            'enabled' => null,
            'log_path' => null,
            'events' => null,
        ], $this->emptyEnv);

        // null ?? true yields true, so (bool) true = true
        self::assertTrue($config->enabled);
        self::assertSame('var/logs/audit.jsonl', $config->logPath);
    }

    #[Test]
    public function fromArrayPreservesExtraKeysSilently(): void
    {
        $config = AuditConfig::fromArray([
            'enabled' => true,
            'unknown_option' => 'should_be_ignored',
        ], $this->emptyEnv);

        self::assertTrue($config->enabled);
        self::assertSame('var/logs/audit.jsonl', $config->logPath);
    }

    #[Test]
    public function fromArrayWithBooleanArrayLogPath(): void
    {
        $config = AuditConfig::fromArray([
            'log_path' => ['/path/a', '/path/b'],
        ], $this->emptyEnv);

        self::assertSame('var/logs/audit.jsonl', $config->logPath);
    }

    #[Test]
    public function fromArrayWithEmptyStringLogPath(): void
    {
        $config = AuditConfig::fromArray([
            'log_path' => '',
        ], $this->emptyEnv);

        self::assertSame('', $config->logPath);
    }

    /**
     * @param array<string, string> $vars
     */
    private function createTempEnvFile(array $vars): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_env_');
        self::assertNotFalse($path);

        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }
}
