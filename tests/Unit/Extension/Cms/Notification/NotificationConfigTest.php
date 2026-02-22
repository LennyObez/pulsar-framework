<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\NotificationConfig;

#[CoversClass(NotificationConfig::class)]
final class NotificationConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_sensible(): void
    {
        $config = new NotificationConfig();

        self::assertFalse($config->enabled);
        self::assertSame(['log'], $config->channels);
        self::assertTrue($config->notifyOnPublish);
        self::assertTrue($config->notifyOnReview);
        self::assertTrue($config->notifyOnComment);
    }

    #[Test]
    public function from_array_with_empty_data_returns_defaults(): void
    {
        $config = NotificationConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(['log'], $config->channels);
        self::assertTrue($config->notifyOnPublish);
        self::assertTrue($config->notifyOnReview);
        self::assertTrue($config->notifyOnComment);
    }

    #[Test]
    public function from_array_applies_custom_values(): void
    {
        $config = NotificationConfig::fromArray([
            'enabled' => true,
            'channels' => ['email', 'database'],
            'notify_on_publish' => false,
            'notify_on_review' => true,
            'notify_on_comment' => false,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['email', 'database'], $config->channels);
        self::assertFalse($config->notifyOnPublish);
        self::assertTrue($config->notifyOnReview);
        self::assertFalse($config->notifyOnComment);
    }

    #[Test]
    public function from_array_casts_types(): void
    {
        $config = NotificationConfig::fromArray([
            'enabled' => 1,
            'notify_on_publish' => 0,
        ]);

        self::assertTrue($config->enabled);
        self::assertFalse($config->notifyOnPublish);
    }
}
