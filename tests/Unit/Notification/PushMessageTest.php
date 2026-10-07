<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\PushMessage;

#[CoversClass(PushMessage::class)]
final class PushMessageTest extends TestCase
{
    #[Test]
    public function constructWithMinimalFields(): void
    {
        $msg = new PushMessage('Hello', 'World');

        self::assertSame('Hello', $msg->title);
        self::assertSame('World', $msg->body);
        self::assertSame([], $msg->data);
        self::assertNull($msg->imageUrl);
        self::assertNull($msg->clickAction);
    }

    #[Test]
    public function constructWithAllFields(): void
    {
        $msg = new PushMessage(
            'Title',
            'Body',
            ['key' => 'val'],
            'https://img.test/photo.png',
            'OPEN_ACTIVITY',
        );

        self::assertSame('Title', $msg->title);
        self::assertSame('Body', $msg->body);
        self::assertSame(['key' => 'val'], $msg->data);
        self::assertSame('https://img.test/photo.png', $msg->imageUrl);
        self::assertSame('OPEN_ACTIVITY', $msg->clickAction);
    }
}
