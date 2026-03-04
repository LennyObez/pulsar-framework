<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\NotificationConfig;

#[CoversClass(NotificationConfig::class)]
final class NotificationConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabledWithLogChannel(): void
    {
        $config = new NotificationConfig();

        self::assertFalse($config->enabled);
        self::assertSame(['log'], $config->channels);
        self::assertTrue($config->notifyOnPublish);
        self::assertTrue($config->notifyOnReview);
        self::assertTrue($config->notifyOnComment);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = NotificationConfig::fromArray([
            'enabled' => true,
            'channels' => ['email', 'database'],
            'notify_on_publish' => false,
            'notify_on_review' => false,
            'notify_on_comment' => false,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['email', 'database'], $config->channels);
        self::assertFalse($config->notifyOnPublish);
        self::assertFalse($config->notifyOnReview);
        self::assertFalse($config->notifyOnComment);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = NotificationConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(['log'], $config->channels);
        self::assertTrue($config->notifyOnPublish);
    }

    #[Test]
    public function fromArrayFiltersNonStringChannels(): void
    {
        $config = NotificationConfig::fromArray([
            'channels' => ['email', 42, true, 'log'],
        ]);

        self::assertSame(['email', 'log'], $config->channels);
    }

    #[Test]
    public function fromArrayUsesDefaultChannelsWhenNotArray(): void
    {
        $config = NotificationConfig::fromArray([
            'channels' => 'email',
        ]);

        self::assertSame(['log'], $config->channels);
    }
}
