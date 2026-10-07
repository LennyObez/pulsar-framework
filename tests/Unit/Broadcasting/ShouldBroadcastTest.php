<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\ShouldBroadcast;
use ReflectionClass;

final class ShouldBroadcastTest extends TestCase
{
    #[Test]
    public function defaultsToEmptyChannelsAndBroadcastToAll(): void
    {
        $attr = new ShouldBroadcast();

        self::assertSame([], $attr->channels);
        self::assertFalse($attr->toOthers);
    }

    #[Test]
    public function channelsAndToOthersCanBeSet(): void
    {
        $attr = new ShouldBroadcast(channels: ['orders', 'admin'], toOthers: true);

        self::assertSame(['orders', 'admin'], $attr->channels);
        self::assertTrue($attr->toOthers);
    }

    #[Test]
    public function isTargetClassAttribute(): void
    {
        $ref = new ReflectionClass(ShouldBroadcast::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertNotEmpty($attrs);
    }
}
