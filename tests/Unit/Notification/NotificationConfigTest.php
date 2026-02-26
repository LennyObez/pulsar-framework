<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Config\NotificationConfig;
use ReflectionClass;

#[CoversClass(NotificationConfig::class)]
final class NotificationConfigTest extends TestCase
{
    private Environment $emptyEnv;

    protected function setUp(): void
    {
        $this->emptyEnv = Environment::load('/nonexistent/.env');
    }

    #[Test]
    public function it_creates_with_defaults(): void
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
    public function it_parses_full_array(): void
    {
        $data = [
            'enabled' => true,
            'default_channels' => ['mail', 'sms'],
            'rate_limit_per_minute' => 120,
            'regulated' => true,
            'audit_hash_enabled' => true,
            'unsubscribe_url_pattern' => 'https://example.com/unsubscribe/{notifiable_id}/{channel}',
        ];

        $config = NotificationConfig::fromArray($data, $this->emptyEnv);

        self::assertTrue($config->enabled);
        self::assertCount(2, $config->defaultChannels);
        self::assertSame(NotificationChannelType::Mail, $config->defaultChannels[0]);
        self::assertSame(NotificationChannelType::Sms, $config->defaultChannels[1]);
        self::assertSame(120, $config->rateLimitPerMinute);
        self::assertTrue($config->regulated);
        self::assertTrue($config->auditHashEnabled);
        self::assertSame(
            'https://example.com/unsubscribe/{notifiable_id}/{channel}',
            $config->unsubscribeUrlPattern,
        );
    }

    #[Test]
    public function it_ignores_invalid_channel_types(): void
    {
        $data = [
            'default_channels' => ['mail', 'nonexistent', 'log'],
        ];

        $config = NotificationConfig::fromArray($data, $this->emptyEnv);

        self::assertCount(2, $config->defaultChannels);
        self::assertSame(NotificationChannelType::Mail, $config->defaultChannels[0]);
        self::assertSame(NotificationChannelType::Log, $config->defaultChannels[1]);
    }

    #[Test]
    public function it_enforces_positive_rate_limit(): void
    {
        $data = ['rate_limit_per_minute' => -1];

        $config = NotificationConfig::fromArray($data, $this->emptyEnv);

        self::assertSame(60, $config->rateLimitPerMinute);
    }

    #[Test]
    public function it_enforces_zero_rate_limit_fallback(): void
    {
        $data = ['rate_limit_per_minute' => 0];

        $config = NotificationConfig::fromArray($data, $this->emptyEnv);

        self::assertSame(60, $config->rateLimitPerMinute);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $config = NotificationConfig::fromArray([], $this->emptyEnv);

        $reflection = new ReflectionClass($config);
        self::assertTrue($reflection->isReadOnly());
    }
}
