<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Config\NotificationConfig;

#[CoversClass(NotificationConfig::class)]
final class NotificationConfigTest extends TestCase
{
    private Environment $emptyEnv;

    protected function setUp(): void
    {
        $this->emptyEnv = Environment::load('/nonexistent/.env');
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new NotificationConfig();

        self::assertFalse($config->enabled);
        self::assertSame([], $config->defaultChannels);
        self::assertSame(60, $config->rateLimitPerMinute);
        self::assertFalse($config->regulated);
        self::assertFalse($config->auditHashEnabled);
        self::assertNull($config->unsubscribeUrlPattern);
    }

    #[Test]
    public function constructorWithCustomValues(): void
    {
        $config = new NotificationConfig(
            enabled: true,
            defaultChannels: [NotificationChannelType::Mail, NotificationChannelType::Sms],
            rateLimitPerMinute: 100,
            regulated: true,
            auditHashEnabled: true,
            unsubscribeUrlPattern: 'https://example.com/unsub/{notifiable_id}/{channel}',
        );

        self::assertTrue($config->enabled);
        self::assertCount(2, $config->defaultChannels);
        self::assertSame(NotificationChannelType::Mail, $config->defaultChannels[0]);
        self::assertSame(NotificationChannelType::Sms, $config->defaultChannels[1]);
        self::assertSame(100, $config->rateLimitPerMinute);
        self::assertTrue($config->regulated);
        self::assertTrue($config->auditHashEnabled);
        self::assertSame('https://example.com/unsub/{notifiable_id}/{channel}', $config->unsubscribeUrlPattern);
    }

    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = NotificationConfig::fromArray([], $this->emptyEnv);

        self::assertFalse($config->enabled);
        self::assertSame([], $config->defaultChannels);
        self::assertSame(60, $config->rateLimitPerMinute);
        self::assertFalse($config->regulated);
        self::assertFalse($config->auditHashEnabled);
        self::assertNull($config->unsubscribeUrlPattern);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $data = [
            'enabled' => true,
            'default_channels' => ['mail', 'sms', 'database'],
            'rate_limit_per_minute' => 120,
            'regulated' => true,
            'audit_hash_enabled' => true,
            'unsubscribe_url_pattern' => 'https://example.com/unsubscribe/{notifiable_id}/{channel}',
        ];

        $config = NotificationConfig::fromArray($data, $this->emptyEnv);

        self::assertTrue($config->enabled);
        self::assertCount(3, $config->defaultChannels);
        self::assertSame(NotificationChannelType::Mail, $config->defaultChannels[0]);
        self::assertSame(NotificationChannelType::Sms, $config->defaultChannels[1]);
        self::assertSame(NotificationChannelType::Database, $config->defaultChannels[2]);
        self::assertSame(120, $config->rateLimitPerMinute);
        self::assertTrue($config->regulated);
        self::assertTrue($config->auditHashEnabled);
        self::assertSame(
            'https://example.com/unsubscribe/{notifiable_id}/{channel}',
            $config->unsubscribeUrlPattern,
        );
    }

    #[Test]
    public function fromArrayIgnoresInvalidChannelTypes(): void
    {
        $config = NotificationConfig::fromArray([
            'default_channels' => ['mail', 'nonexistent', 'log', '', 'webhook'],
        ], $this->emptyEnv);

        self::assertCount(3, $config->defaultChannels);
        self::assertSame(NotificationChannelType::Mail, $config->defaultChannels[0]);
        self::assertSame(NotificationChannelType::Log, $config->defaultChannels[1]);
        self::assertSame(NotificationChannelType::Webhook, $config->defaultChannels[2]);
    }

    #[Test]
    public function fromArrayIgnoresNonStringChannelValues(): void
    {
        $config = NotificationConfig::fromArray([
            'default_channels' => ['mail', 42, null, true, 'sms'],
        ], $this->emptyEnv);

        self::assertCount(2, $config->defaultChannels);
        self::assertSame(NotificationChannelType::Mail, $config->defaultChannels[0]);
        self::assertSame(NotificationChannelType::Sms, $config->defaultChannels[1]);
    }

    #[Test]
    public function fromArrayHandlesNonArrayChannels(): void
    {
        $config = NotificationConfig::fromArray([
            'default_channels' => 'mail',
        ], $this->emptyEnv);

        self::assertSame([], $config->defaultChannels);
    }

    #[Test]
    public function fromArrayEnforcesPositiveRateLimit(): void
    {
        $config = NotificationConfig::fromArray([
            'rate_limit_per_minute' => -5,
        ], $this->emptyEnv);

        self::assertSame(60, $config->rateLimitPerMinute);
    }

    #[Test]
    public function fromArrayEnforcesZeroRateLimitFallback(): void
    {
        $config = NotificationConfig::fromArray([
            'rate_limit_per_minute' => 0,
        ], $this->emptyEnv);

        self::assertSame(60, $config->rateLimitPerMinute);
    }

    #[Test]
    public function fromArrayCoercesStringRateLimit(): void
    {
        $envFile = $this->createTempEnvFile(['NOTIFICATION_RATE_LIMIT' => '200']);
        $env = Environment::load($envFile);

        $config = NotificationConfig::fromArray([], $env);

        self::assertSame(200, $config->rateLimitPerMinute);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayFallsBackOnNonBoolEnabled(): void
    {
        $config = NotificationConfig::fromArray([
            'enabled' => 'yes',
        ], $this->emptyEnv);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayFallsBackOnNonBoolRegulated(): void
    {
        $config = NotificationConfig::fromArray([
            'regulated' => 1,
        ], $this->emptyEnv);

        self::assertFalse($config->regulated);
    }

    #[Test]
    public function fromArrayFallsBackOnNonBoolAuditHash(): void
    {
        $config = NotificationConfig::fromArray([
            'audit_hash_enabled' => 'on',
        ], $this->emptyEnv);

        self::assertFalse($config->auditHashEnabled);
    }

    #[Test]
    public function fromArrayFallsBackOnNonStringUnsubscribePattern(): void
    {
        $config = NotificationConfig::fromArray([
            'unsubscribe_url_pattern' => 42,
        ], $this->emptyEnv);

        self::assertNull($config->unsubscribeUrlPattern);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesEnabled(): void
    {
        $envFile = $this->createTempEnvFile(['NOTIFICATION_ENABLED' => 'true']);
        $env = Environment::load($envFile);

        $config = NotificationConfig::fromArray(['enabled' => false], $env);

        self::assertTrue($config->enabled);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesRegulated(): void
    {
        $envFile = $this->createTempEnvFile(['NOTIFICATION_REGULATED' => 'true']);
        $env = Environment::load($envFile);

        $config = NotificationConfig::fromArray(['regulated' => false], $env);

        self::assertTrue($config->regulated);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesAuditHash(): void
    {
        $envFile = $this->createTempEnvFile(['NOTIFICATION_AUDIT_HASH' => 'true']);
        $env = Environment::load($envFile);

        $config = NotificationConfig::fromArray(['audit_hash_enabled' => false], $env);

        self::assertTrue($config->auditHashEnabled);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesUnsubscribeUrl(): void
    {
        $envFile = $this->createTempEnvFile([
            'NOTIFICATION_UNSUBSCRIBE_URL' => 'https://env.example.com/unsub',
        ]);
        $env = Environment::load($envFile);

        $config = NotificationConfig::fromArray([
            'unsubscribe_url_pattern' => 'https://config.example.com/unsub',
        ], $env);

        self::assertSame('https://env.example.com/unsub', $config->unsubscribeUrlPattern);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = NotificationConfig::fromArray([
            'enabled' => null,
            'default_channels' => null,
            'rate_limit_per_minute' => null,
            'regulated' => null,
            'audit_hash_enabled' => null,
            'unsubscribe_url_pattern' => null,
        ], $this->emptyEnv);

        self::assertFalse($config->enabled);
        self::assertSame([], $config->defaultChannels);
        self::assertSame(60, $config->rateLimitPerMinute);
        self::assertFalse($config->regulated);
        self::assertFalse($config->auditHashEnabled);
        self::assertNull($config->unsubscribeUrlPattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allChannelTypesProvider(): iterable
    {
        yield 'mail' => ['mail'];
        yield 'sms' => ['sms'];
        yield 'database' => ['database'];
        yield 'slack' => ['slack'];
        yield 'webhook' => ['webhook'];
        yield 'log' => ['log'];
    }

    #[Test]
    #[DataProvider('allChannelTypesProvider')]
    public function fromArrayAcceptsAllValidChannelTypes(string $channel): void
    {
        $config = NotificationConfig::fromArray([
            'default_channels' => [$channel],
        ], $this->emptyEnv);

        self::assertCount(1, $config->defaultChannels);
        self::assertSame(NotificationChannelType::from($channel), $config->defaultChannels[0]);
    }

    #[Test]
    public function fromArrayRateLimitNonNumericStringFallsBackToDefault(): void
    {
        $config = NotificationConfig::fromArray([
            'rate_limit_per_minute' => 'fast',
        ], $this->emptyEnv);

        self::assertSame(60, $config->rateLimitPerMinute);
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
