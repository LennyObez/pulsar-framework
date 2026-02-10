<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Publishing\ChannelRegistry;
use Pulsar\Extension\Cms\Publishing\PublishingChannelInterface;
use Pulsar\Extension\Cms\Publishing\PublishResult;

#[CoversClass(ChannelRegistry::class)]
final class ChannelRegistryTest extends TestCase
{
    #[Test]
    public function get_returns_null_for_unknown_channel(): void
    {
        $registry = new ChannelRegistry();
        self::assertNull($registry->get('nonexistent'));
    }

    #[Test]
    public function register_and_get(): void
    {
        $channel = $this->createChannel('web', enabled: true);
        $registry = new ChannelRegistry();

        $registry->register($channel);

        self::assertSame($channel, $registry->get('web'));
    }

    #[Test]
    public function all_returns_all_registered_channels(): void
    {
        $web = $this->createChannel('web', enabled: true);
        $rss = $this->createChannel('rss', enabled: false);

        $registry = new ChannelRegistry();
        $registry->register($web);
        $registry->register($rss);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertContains($web, $all);
        self::assertContains($rss, $all);
    }

    #[Test]
    public function get_enabled_returns_only_enabled_channels(): void
    {
        $web = $this->createChannel('web', enabled: true);
        $rss = $this->createChannel('rss', enabled: false);
        $staticSite = $this->createChannel('static', enabled: true);

        $registry = new ChannelRegistry();
        $registry->register($web);
        $registry->register($rss);
        $registry->register($staticSite);

        $enabled = $registry->getEnabled();
        self::assertCount(2, $enabled);
        self::assertContains($web, $enabled);
        self::assertContains($staticSite, $enabled);
        self::assertNotContains($rss, $enabled);
    }

    #[Test]
    public function register_replaces_channel_with_same_name(): void
    {
        $original = $this->createChannel('web', enabled: true);
        $replacement = $this->createChannel('web', enabled: false);

        $registry = new ChannelRegistry();
        $registry->register($original);
        $registry->register($replacement);

        self::assertSame($replacement, $registry->get('web'));
        self::assertCount(1, $registry->all());
    }

    #[Test]
    public function empty_registry_returns_empty_arrays(): void
    {
        $registry = new ChannelRegistry();

        self::assertSame([], $registry->all());
        self::assertSame([], $registry->getEnabled());
    }

    private function createChannel(string $name, bool $enabled): PublishingChannelInterface
    {
        return new class ($name, $enabled) implements PublishingChannelInterface {
            public function __construct(
                private readonly string $channelName,
                private readonly bool $enabled,
            ) {}

            public function name(): string
            {
                return $this->channelName;
            }

            public function publish(Content $content, ContentTranslation $translation): PublishResult
            {
                return PublishResult::success($this->channelName);
            }

            public function unpublish(Content $content): PublishResult
            {
                return PublishResult::success($this->channelName);
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }
        };
    }
}
