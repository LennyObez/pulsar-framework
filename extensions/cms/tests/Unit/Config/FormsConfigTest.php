<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\FormsConfig;

#[CoversClass(FormsConfig::class)]
final class FormsConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreReasonable(): void
    {
        $config = new FormsConfig();

        self::assertSame(5.0, $config->spamThreshold);
        self::assertSame(10, $config->rateLimitPerHour);
        self::assertSame([], $config->notificationRecipients);
        self::assertSame('_hp_field', $config->honeypotFieldName);
        self::assertSame('0000', $config->powDifficulty);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = FormsConfig::fromArray([
            'spam_threshold' => 3.5,
            'rate_limit_per_hour' => 5,
            'notification_recipients' => ['admin@example.com', 'ops@example.com'],
            'honeypot_field_name' => 'trap_field',
            'pow_difficulty' => '00000',
        ]);

        self::assertSame(3.5, $config->spamThreshold);
        self::assertSame(5, $config->rateLimitPerHour);
        self::assertSame(['admin@example.com', 'ops@example.com'], $config->notificationRecipients);
        self::assertSame('trap_field', $config->honeypotFieldName);
        self::assertSame('00000', $config->powDifficulty);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = FormsConfig::fromArray([]);

        self::assertSame(5.0, $config->spamThreshold);
        self::assertSame(10, $config->rateLimitPerHour);
        self::assertSame([], $config->notificationRecipients);
        self::assertSame('_hp_field', $config->honeypotFieldName);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = FormsConfig::fromArray([
            'spam_threshold' => 'high',
            'rate_limit_per_hour' => '10',
            'notification_recipients' => 'admin@example.com',
            'honeypot_field_name' => 42,
            'pow_difficulty' => 0,
        ]);

        // Non-float for spam_threshold → default
        self::assertSame(5.0, $config->spamThreshold);
        // Non-int → default
        self::assertSame(10, $config->rateLimitPerHour);
        // Non-array → default empty list
        self::assertSame([], $config->notificationRecipients);
        // Non-string → defaults
        self::assertSame('_hp_field', $config->honeypotFieldName);
        self::assertSame('0000', $config->powDifficulty);
    }

    #[Test]
    public function fromArrayFiltersNonStringRecipients(): void
    {
        $config = FormsConfig::fromArray([
            'notification_recipients' => ['admin@example.com', 42, true, 'ops@example.com'],
        ]);

        self::assertSame(['admin@example.com', '', '', 'ops@example.com'], $config->notificationRecipients);
    }
}
