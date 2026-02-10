<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Notification;

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

    // ---- Boundary / negative tests ----

    #[Test]
    public function defaults_and_from_array_empty_are_identical(): void
    {
        $direct = new NotificationConfig();
        $fromEmpty = NotificationConfig::fromArray([]);

        self::assertSame($direct->enabled, $fromEmpty->enabled);
        self::assertSame($direct->channels, $fromEmpty->channels);
        self::assertSame($direct->notifyOnPublish, $fromEmpty->notifyOnPublish);
        self::assertSame($direct->notifyOnReview, $fromEmpty->notifyOnReview);
        self::assertSame($direct->notifyOnComment, $fromEmpty->notifyOnComment);
    }

    #[Test]
    public function disabling_notify_on_publish_does_not_affect_other_flags(): void
    {
        $config = NotificationConfig::fromArray([
            'enabled' => true,
            'notify_on_publish' => false,
        ]);

        self::assertFalse($config->notifyOnPublish);
        self::assertTrue($config->notifyOnReview, 'notifyOnReview must default to true');
        self::assertTrue($config->notifyOnComment, 'notifyOnComment must default to true');
    }

    #[Test]
    public function empty_channels_list_is_preserved(): void
    {
        $config = NotificationConfig::fromArray([
            'channels' => [],
        ]);

        self::assertSame([], $config->channels);
    }

    #[Test]
    public function multiple_channels_are_preserved(): void
    {
        $channels = ['email', 'sms', 'slack', 'webhook'];
        $config = NotificationConfig::fromArray(['channels' => $channels]);

        self::assertSame($channels, $config->channels);
    }

    #[Test]
    public function enabling_config_does_not_auto_select_channels(): void
    {
        // enabling 'enabled' must not change channel list from the default
        $config = NotificationConfig::fromArray(['enabled' => true]);

        self::assertTrue($config->enabled);
        self::assertSame(['log'], $config->channels, 'channels must remain at default');
    }
}
